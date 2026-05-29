<?php

namespace App\Modules\Feedback;

use App\Modules\Feedback\Actions\HandleFeedbackRating;
use App\Modules\Feedback\Actions\SendFeedbackForm;
use Illuminate\Support\ServiceProvider;

class FeedbackServiceProvider extends ServiceProvider
{
    /**
     * Register Feedback module bindings.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(SendFeedbackForm::class);
        $this->app->bind(HandleFeedbackRating::class);
    }

    /**
     * Bootstrap Feedback module.
     *
     * @return void
     */
    public function boot(): void
    {
        //
    }
}
