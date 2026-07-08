<?php

declare(strict_types=1);

/**
 * Minimal stand-ins for the Milpa HOST APP classes `ControllerVerifier` checks generated controllers
 * against (`Milpa\app\Providers\BaseController` / `HttpResponse`). This package ships zero coupling
 * to those classes at runtime — `coa:make controller` scaffolds code that targets them in a real host
 * app (see `Make/stubs/controller.php.stub`) — but `ControllerVerifierTest` needs SOMETHING real and
 * autoloadable at those exact FQCNs to exercise `isSubclassOf()` honestly, so this file defines the
 * smallest possible stand-ins. Loaded via composer.json's `autoload-dev.files` (always present in the
 * test process, never shipped to a consumer).
 */

namespace Milpa\app\Providers {
    class BaseController
    {
        public function __construct(mixed $container)
        {
        }
    }

    class HttpResponse
    {
    }
}
