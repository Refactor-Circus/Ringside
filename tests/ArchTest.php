<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('the package source declares strict types')
    ->expect('RefactorCircus\Ringside')
    ->toUseStrictTypes();
