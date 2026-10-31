<?php

namespace Hibira\FilamentCollab\Tests\Fixtures\V4\Pages;

use Filament\Resources\Pages\ListRecords;
use Hibira\FilamentCollab\Tests\Fixtures\V4\PostResource;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;
}
