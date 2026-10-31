<?php

namespace Hibira\FilamentCollab\Tests\Fixtures\V3\Pages;

use Filament\Resources\Pages\ListRecords;
use Hibira\FilamentCollab\Tests\Fixtures\V3\PostResource;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;
}
