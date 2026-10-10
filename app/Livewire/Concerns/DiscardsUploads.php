<?php

namespace App\Livewire\Concerns;

use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Removes a Livewire temporary upload at once rather than leaving it for
 * Livewire's 24-hour cleanup — uploads here can hold credentials.
 *
 * Livewire stores each upload with a `<file>.json` metadata file beside it
 * (original name, size, hash) that TemporaryUploadedFile::delete() leaves
 * behind, so both are deleted (decisions.md D-019).
 */
trait DiscardsUploads
{
    protected function discardUpload(?TemporaryUploadedFile $upload): void
    {
        if (! $upload) {
            return;
        }

        $metaFile = FileUploadConfiguration::path($upload->getFilename().'.json', false);

        $upload->delete();
        FileUploadConfiguration::storage()->delete($metaFile);
    }
}
