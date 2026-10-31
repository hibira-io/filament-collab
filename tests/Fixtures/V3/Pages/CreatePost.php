<?php

namespace Hibira\FilamentCollab\Tests\Fixtures\V3\Pages;

use Filament\Resources\Pages\CreateRecord;
use Hibira\FilamentCollab\Tests\Fixtures\V3\PostResource;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;
}
