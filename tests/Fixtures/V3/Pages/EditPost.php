<?php

namespace Hibira\FilamentCollab\Tests\Fixtures\V3\Pages;

use Filament\Resources\Pages\EditRecord;
use Hibira\FilamentCollab\Tests\Fixtures\V3\PostResource;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;
}
