<?php

declare(strict_types=1);

namespace Tarjim\Wire;

interface Transport
{
    /** A GET. Never throws and never follows a redirect: a 3xx comes back as status 0. */
    public function send(Request $request): Response;
}
