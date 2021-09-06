<?php

namespace Nocs\LaravelFilepond\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nocs\LaravelFilepond\Filepond;

use Illuminate\Support\Facades\Log;

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

        $fieldName = $request->input("fieldName");

        if ($input === null) {
            return Response::make(config('filepond.input_name') . ' is required', 422, [
                'Content-Type' => 'text/plain',
            ]);
        }

        $file = $input;
        while (is_array($file)) {
            $file = isset($file[0]) ? $file[0] : null;
        }
        $path = config('filepond.temporary_files_path', 'filepond');
        $disk = config('filepond.temporary_files_disk', 'local');

        if (! ($newFile = $file->storeAs($path . DIRECTORY_SEPARATOR . Str::random(), $file->getClientOriginalName(), $disk))) {
            return Response::make('Could not save file', 500, [
                'Content-Type' => 'text/plain',
            ]);
        }

        $response = [
            'serverId' => $this->filepond->getServerIdFromPath(Storage::disk($disk)->path($newFile)),
            'fieldName' => $fieldName,
        ];

        return Response::make(json_encode($response), 200, [
            'Content-Type' => 'text/plain',
        ]);
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
