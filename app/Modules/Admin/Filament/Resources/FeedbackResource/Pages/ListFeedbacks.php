<?php

namespace App\Modules\Admin\Filament\Resources\FeedbackResource\Pages;

use App\Modules\Admin\Filament\Resources\FeedbackResource;
use Filament\Resources\Pages\ListRecords;

class ListFeedbacks extends ListRecords
{
    protected static string $resource = FeedbackResource::class;
}
