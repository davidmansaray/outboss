<?php

use App\Mcp\OutbossServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', OutbossServer::class)->middleware(['throttle:ingress', 'auth:sanctum', 'throttle:mcp']);

Mcp::local('outboss', OutbossServer::class);
