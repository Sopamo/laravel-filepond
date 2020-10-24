<?php

namespace Sopamo\LaravelFilepond\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
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

        if ($input === null) {
            // Chunk upload
            $newDir = Storage::disk($disk)
                ->makeDirectory($path . DIRECTORY_SEPARATOR . Str::random());

            return Response::make($this->filepond->getServerIdFromPath(Storage::disk($disk)->path($newDir)), 200, [
                'Content-Type' => 'text/plain',
            ]);
        }

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
        error_reporting(E_ERROR);

        // Retrieve upload ID
        $id = $request->get('patch');

        // Load chunks directory
        $path = $this->filepond->getPathFromServerId($id);
        $dir = $filePath;

        // Get patch info
        $offset = $_SERVER['HTTP_UPLOAD_OFFSET'];
        $length = $_SERVER['HTTP_UPLOAD_LENGTH'];

        // Validate patch info
        if (!is_numeric($offset) || !is_numeric($length))
            return http_response_code(400);

        // Retrieve disk
        $disk = config('filepond.temporary_files_disk', 'local');

        // Store chunk
        Storage::disk($disk)
            ->path($path)
            ->put('.patch.' . $offset, fopen('php://input', 'rb'));


        // calculate total size of patches
        $size = 0;
        $patch = glob($dir . '.patch.*');
        foreach ($patch as $filename) {
            $size += filesize($filename);
        }
        // if total size equals length of file we have gathered all patch files
        if ($size == $length) {
            // create output file
            $file_handle = fopen($dir, 'wb');
            // write patches to file
            foreach ($patch as $filename) {
                // get offset from filename
                list($dir, $offset) = explode('.patch.', $filename, 2);
                // read patch and close
                $patch_handle = fopen($filename, 'rb');
                $patch_contents = fread($patch_handle, filesize($filename));
                fclose($patch_handle);

                // apply patch
                fseek($file_handle, $offset);
                fwrite($file_handle, $patch_contents);
            }
            // remove patches
            foreach ($patch as $filename) {
                unlink($filename);
            }
            // done with file
            fclose($file_handle);
        }
        return Response::make('',204);
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
