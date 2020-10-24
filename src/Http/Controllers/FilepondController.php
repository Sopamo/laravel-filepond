<?php

namespace Sopamo\LaravelFilepond\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Sopamo\LaravelFilepond\Filepond;

class FilepondController extends BaseController
{
    /**
     * @var Filepond
     */
    private $filepond;

    public function __construct(Filepond $filepond)
    {
        $this->filepond = $filepond;
    }

    /**
     * Uploads the file to the temporary directory
     * and returns an encrypted path to the file
     *
     * @param  Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function upload(Request $request)
    {
        $input = $request->file(config('filepond.input_name'));

        $file = is_array($input) ? $input[0] : $input;
        $path = config('filepond.temporary_files_path', 'filepond');
        $disk = config('filepond.temporary_files_disk', 'local');

        // Chunk upload
        if ($input === null)
            $file = new UploadedFile(tempnam('/tmp', 'filepond_'), Str::random());

        if (! ($newFile = $file->storeAs($path . DIRECTORY_SEPARATOR . Str::random(), $file->getClientOriginalName(), $disk))) {
            return Response::make('Could not save file', 500, [
                'Content-Type' => 'text/plain',
            ]);
        }

        return Response::make($this->filepond->getServerIdFromPath(Storage::disk($disk)->path($newFile)), 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Save chunk Parts.
     * @param Request $request
     * @return \Illuminate\Http\Response|int
     */
    public function chunk(Request $request)
    {
        // Retrieve upload ID
        $id = $request->get('patch');

        // Retrieve disk
        $disk = config('filepond.temporary_files_disk', 'local');
        $diskPath = Storage::disk($disk)
            ->path('');

        // Load chunks directory
        $path = $this->filepond->getPathFromServerId($id);
        $pathRelative = str_replace($diskPath, '', $path);

        // Get patch info
        $offset = $_SERVER['HTTP_UPLOAD_OFFSET'];
        $length = $_SERVER['HTTP_UPLOAD_LENGTH'];

        // Validate patch info
        if (!is_numeric($offset) || !is_numeric($length))
            return http_response_code(400);

        // Store chunk
        Storage::disk($disk)
            ->put($pathRelative . '.patch.' . $offset, file_get_contents('php://input'));

        // Check total chunks size
        $size = 0;
        $chunks = Storage::disk($disk)
            ->files(dirname($pathRelative));
        foreach ($chunks as $chunk)
            $size += Storage::disk($disk)
                ->size($chunk);

        // Process finished upload
        if ($size == $length)
        {
            // Sort chunks
            $chunks = collect($chunks);
            $chunks = $chunks->keyBy(function ($chunk) {
                return substr($chunk, strrpos($chunk, '.')+1);
            });
            $chunks = $chunks->sortKeys();

            // Create file
            $handle = fopen($path, 'wb');

            $contents = '';
            // Iterate chunks
            foreach ($chunks as $chunk)
            {
                // Get chunk contents
                $contents .= Storage::disk($disk)
                    ->get($chunk);

                fwrite($handle, $contents);

                // Remove chunk
                Storage::disk($disk)
                    ->delete($chunk);
            }

            // Close file
            fclose($handle);

            // Append chunks
            Storage::disk($disk)
                ->put($pathRelative, $contents);
        }

        return Response::make('', 204);
    }

    /**
     * Takes the given encrypted filepath and deletes
     * it if it hasn't been tampered with
     *
     * @param  Request $request
     *
     * @return mixed
     */
    public function delete(Request $request)
    {
        $filePath = $this->filepond->getPathFromServerId($request->getContent());
        if (Storage::disk(config('filepond.temporary_files_disk', 'local'))->delete($filePath)) {
            return Response::make('', 200, [
                'Content-Type' => 'text/plain',
            ]);
        }

        return Response::make('', 500, [
            'Content-Type' => 'text/plain',
        ]);
    }
}

