<?php

namespace Hibira\FilamentCollab\Tests\Fixtures\V3;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Hibira\FilamentCollab\Forms\Components\CollaborativeEditor;
use Hibira\FilamentCollab\Tests\Fixtures\Post;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'posts';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->required(),
            CollaborativeEditor::make('body')->placeholder('Write the announcement…')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit' => Pages\EditPost::route('/{record}/edit'),
        ];
    }
}
