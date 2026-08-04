<?php

namespace App\Http\Controllers;

use App\Models\Obj;
use App\Models\File;
use Illuminate\Http\Request;
use App\Policies\FilePolicy;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function index(Request $request)
    {
        $uuid = $request->query('uuid')
            ?? Obj::whereNull('parent_id')->value('uuid');

    	$object = Obj::with('children.objectable', 'ancestorsAndSelf.objectable')
    	    ->where('uuid', $uuid)
    	    ->firstOrFail();

    	return view('files', [
    		'object' => $object,
    		'ancestors' => $object->ancestorsAndSelf()->breadthFirst()->get()
    	]);
    }

    public function download(File $file)
    {
        $this->authorize('download', $file);

        return Storage::disk('local')->download($file->path, $file->name);
    }
}
