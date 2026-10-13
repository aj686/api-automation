<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\Project;
use App\Services\CollectionImporter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The stored collection file, edits included, for importing back into
 * Postman (decisions.md D-038). {collection:slug} is scoped to {project}.
 */
class CollectionDownloadController extends Controller
{
    public function __invoke(Project $project, Collection $collection): StreamedResponse
    {
        return Storage::disk(CollectionImporter::DISK)->download(
            $collection->stored_path,
            $collection->slug.'.postman_collection.json',
            ['Content-Type' => 'application/json'],
        );
    }
}
