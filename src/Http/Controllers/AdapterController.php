<?php

namespace JarredCain\CanvasLms\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use JarredCain\CanvasLms\Adapters\AdapterService;

/**
 * HTTP controller for receiving external system mutation payloads and applying them to Canvas.
 *
 * This controller is publishable so you can customize it in your application —
 * most importantly to add authentication middleware and request validation.
 *
 * ## Publishing
 *
 * ```bash
 * php artisan vendor:publish --tag=canvas-adapter
 * ```
 *
 * This copies the controller to app/Http/Controllers/Canvas/AdapterController.php.
 * Update the service provider route registration to point to your published copy.
 *
 * ## Enabling the route
 *
 * Set 'adapters.routes_enabled' => true in config/canvas.php.
 * The route registered is:
 *   POST /canvas/adapter/{resource}/{id}
 *
 * ## Request format
 *
 * Header:  X-Canvas-Source-System: salesforce   (identifies which system is sending the data)
 * Body:    JSON payload in the source system's field names
 *
 * Example:
 *   POST /canvas/adapter/user/42
 *   X-Canvas-Source-System: salesforce
 *   {"Full_Name__c": "Ada Lovelace", "Email": "ada@university.edu"}
 *
 * ## Security note
 *
 * The default implementation has no authentication. Before enabling this route in production,
 * publish the controller and add appropriate middleware (e.g., signed URLs, API keys,
 * IP allowlists) to protect against unauthorized Canvas mutations.
 */
class AdapterController extends Controller
{
    public function __construct(private readonly AdapterService $adapterService)
    {
    }

    /**
     * Receive an external system payload and apply it to the Canvas resource.
     *
     * @param  Request     $request   Must include X-Canvas-Source-System header and JSON body
     * @param  string      $resource  Canvas resource type matching a config adapter key (e.g. 'user', 'course')
     * @param  int|string  $id        Canvas resource ID to update
     */
    public function mutate(Request $request, string $resource, int|string $id): JsonResponse
    {
        $system  = $request->header('X-Canvas-Source-System');
        $payload = $request->json()->all();

        $result = $this->adapterService->push($resource, $id, $system, $payload);

        return response()->json($result->toArray());
    }
}
