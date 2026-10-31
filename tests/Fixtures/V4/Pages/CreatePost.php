<?php

namespace Hibira\FilamentCollab\Tests\Fixtures\V4\Pages;

use Filament\Resources\Pages\CreateRecord;
use Hibira\FilamentCollab\Tests\Fixtures\V4\PostResource;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;
}
