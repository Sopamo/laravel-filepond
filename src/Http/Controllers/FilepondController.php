<?php

namespace Sopamo\LaravelFilepond\Http\Controllers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Crypt;
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
     * @param Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function upload(Request $request)
    {
        $input = $request->file(config('filepond.input_name'));

        if ($input === null) {
            return $this->handleChunkInitialization();
        }

        $file = is_array($input) ? $input[0] : $input;
        $path = config('filepond.temporary_files_path', 'filepond');
        $disk = config('filepond.temporary_files_disk', 'local');

        if (!($newFile = $file->storeAs($path . DIRECTORY_SEPARATOR . Str::random(), $file->getClientOriginalName(), $disk))) {
            return Response::make('Could not save file', 500, [
                'Content-Type' => 'text/plain',
            ]);
        }

        return Response::make($this->filepond->getServerIdFromPath($newFile), 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * This handles the case where filepond wants to start uploading chunks of a file
     * See: https://pqina.nl/filepond/docs/patterns/api/server/
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    private function handleChunkInitialization()
    {
        $randomId = Str::random();
        $path = config('filepond.temporary_files_path', 'filepond');
        $disk = config('filepond.temporary_files_disk', 'local');

        $fileLocation = $path . DIRECTORY_SEPARATOR . $randomId;

        $fileCreated = Storage::disk($disk)
            ->put($fileLocation, '');

        if (!$fileCreated) {
            abort(500, 'Could not create file');
        }
        $filepondId = $this->filepond->getServerIdFromPath($fileLocation);

        return Response::make($filepondId, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Handle a single chunk
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     * @throws FileNotFoundException
     */
    public function chunk(Request $request)
    {
        // Retrieve upload ID
        $encryptedPath = $request->input('patch');
        if (!$encryptedPath) {
            abort(400, 'No id given');
        }

        try {
            $finalFilePath = Crypt::decryptString($encryptedPath);
            $id = basename($finalFilePath);
        } catch (DecryptException $e) {
            abort(400, 'Invalid encryption for id');
        }

        // Retrieve disk
        $disk = config('filepond.temporary_files_disk', 'local');

        // Load chunks directory
        $basePath = config('filepond.chunks_path') . DIRECTORY_SEPARATOR . $id;

        // Get patch info
        $offset = $request->server('HTTP_UPLOAD_OFFSET');
        $length = $request->server('HTTP_UPLOAD_LENGTH');

        // Validate patch info
        if (!is_numeric($offset) || !is_numeric($length)) {
            abort(400, 'Invalid chunk length or offset');
        }

        // Store chunk
        Storage::disk($disk)
            ->put($basePath . DIRECTORY_SEPARATOR . 'patch.' . $offset, $request->getContent(), ['mimetype' => 'application/octet-stream']);

        $this->persistFileIfDone($disk, $basePath, $length, $finalFilePath);
        
        return Response::make('', 204);
    }

    /**
     * This checks if all chunks have been uploaded and if they have, it creates the final file
     *
     * @param $disk
     * @param $basePath
     * @param $length
     * @param $finalFilePath
     * @throws FileNotFoundException
     */
    private function persistFileIfDone($disk, $basePath, $length, $finalFilePath)
    {
        // Check total chunks size
        $size = 0;
        $chunks = Storage::disk($disk)
            ->files($basePath);

        foreach ($chunks as $chunk) {
            $size += Storage::disk($disk)
                ->size($chunk);
        }

        // Process finished upload
        if ($size < $length) {
            return;
        }

        // Sort chunks
        $chunks = collect($chunks);
        $chunks = $chunks->keyBy(function ($chunk) {
            return substr($chunk, strrpos($chunk, '.') + 1);
        });
        $chunks = $chunks->sortKeys();

        // Append each chunk to the final file
        $data = '';
        foreach ($chunks as $chunk) {
            // Get chunk contents
            $chunkContents = Storage::disk($disk)
                ->get($chunk);

            // Laravel's local disk implementation is quite inefficient for appending data to existing files
            // To be at least a bit more efficient, we build the final content ourselves, but the most efficient
            // Way to do this would be to append using the driver's capabilities
            $data .= $chunkContents;
            unset($chunkContents);
        }
        Storage::disk($disk)->put($finalFilePath, $data, ['mimetype' => 'application/octet-stream']);
        Storage::disk($disk)->deleteDirectory($basePath);
    }

    /**
     * Takes the given encrypted filepath and deletes
     * it if it hasn't been tampered with
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function delete(Request $request)
    {
        $filePath = $this->filepond->getPathFromServerId($request->getContent());
        $folderPath = dirname($filePath);
        if (Storage::disk(config('filepond.temporary_files_disk', 'local'))->deleteDirectory($folderPath)) {
            return Response::make('', 200, [
                'Content-Type' => 'text/plain',
            ]);
        }

        return Response::make('', 500, [
            'Content-Type' => 'text/plain',
        ]);
    }
}

