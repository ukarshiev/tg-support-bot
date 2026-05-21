<?php

namespace App\Modules\Admin\Filament\Resources\BotUserResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FeedbacksRelationManager extends RelationManager
{
    protected static string $relationship = 'feedbacks';

    protected static ?string $title = 'История отзывов';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    /**
     * @param Table $table
     *
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rating')
                    ->label('Оценка')
                    ->sortable()
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? str_repeat('⭐', $state) . " ({$state})" : '—'),
                TextColumn::make('comment')
                    ->label('Комментарий')
                    ->limit(60)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'awaiting_rating' => 'warning',
                        'completed_no_comment' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'awaiting_rating' => 'Ожидает оценки',
                        'completed_no_comment' => 'Оценён',
                        default => $state,
                    }),
                TextColumn::make('closed_at')
                    ->label('Закрыто')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Read-only relation manager — no create or edit allowed.
     *
     * @return bool
     */
    public function canCreate(): bool
    {
        return false;
    }
}
