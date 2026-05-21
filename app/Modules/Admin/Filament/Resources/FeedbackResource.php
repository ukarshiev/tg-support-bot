<?php

namespace App\Modules\Admin\Filament\Resources;

use App\Models\Feedback;
use App\Modules\Admin\Filament\Resources\FeedbackResource\Pages\ListFeedbacks;
use App\Modules\Admin\Filament\Resources\FeedbackResource\Pages\ViewFeedback;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FeedbackResource extends Resource
{
    protected static ?string $model = Feedback::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationLabel = 'Отзывы';

    protected static ?string $modelLabel = 'Отзыв';

    protected static ?string $pluralModelLabel = 'Отзывы';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    /**
     * @param Table $table
     *
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('botUser.chat_id')
                    ->label('User (Chat ID)')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('botUser.platform')
                    ->label('Платформа')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'telegram' => 'info',
                        'vk' => 'primary',
                        'max' => 'success',
                        default => 'warning',
                    }),
                TextColumn::make('rating')
                    ->label('Оценка')
                    ->sortable()
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? str_repeat('⭐', $state) . " ({$state})" : '—'),
                TextColumn::make('comment')
                    ->label('Комментарий')
                    ->limit(60)
                    ->placeholder('—'),
                TextColumn::make('closed_at')
                    ->label('Закрыто')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
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
            ])
            ->filters([
                SelectFilter::make('rating')
                    ->label('Оценка')
                    ->options([
                        1 => '⭐ 1',
                        2 => '⭐⭐ 2',
                        3 => '⭐⭐⭐ 3',
                        4 => '⭐⭐⭐⭐ 4',
                        5 => '⭐⭐⭐⭐⭐ 5',
                    ]),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'awaiting_rating' => 'Ожидает оценки',
                        'completed_no_comment' => 'Оценён',
                    ]),
                SelectFilter::make('platform')
                    ->label('Платформа')
                    ->relationship('botUser', 'platform')
                    ->options([
                        'telegram' => 'Telegram',
                        'vk' => 'VK',
                        'max' => 'Max',
                    ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListFeedbacks::route('/'),
            'view' => ViewFeedback::route('/{record}'),
        ];
    }

    /**
     * Feedback records are read-only — no create, edit, or delete actions.
     *
     * @return bool
     */
    public static function canCreate(): bool
    {
        return false;
    }
}
