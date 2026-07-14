<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('support', static fn ($user): bool => $user !== null);
