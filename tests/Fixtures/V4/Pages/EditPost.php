<?php

namespace Hibira\FilamentCollab\Tests\Fixtures\V4\Pages;

use Filament\Resources\Pages\EditRecord;
use Hibira\FilamentCollab\Tests\Fixtures\V4\PostResource;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;
}
