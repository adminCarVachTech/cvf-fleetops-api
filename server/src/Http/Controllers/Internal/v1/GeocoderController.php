<?php

// namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

// use Fleetbase\FleetOps\Models\Place;
// use Fleetbase\FleetOps\Support\Utils;
// use Fleetbase\Http\Controllers\Controller;
// use Geocoder\Laravel\Facades\Geocoder;
// use Illuminate\Http\Request;

// class GeocoderController extends Controller
// {
//     /**
//      * Reverse geocodes the given coordinates and returns the results as JSON.
//      *
//      * @param Request $request the HTTP request object
//      *
//      * @return \Illuminate\Http\Response the JSON response with the geocoded results
//      */
//     public function reverse(Request $request)
//     {
//         $query = $request->or(['coordinates', 'query']);
//         $single = $request->boolean('single');

//         /** @var \Fleetbase\LaravelMysqlSpatial\Types\Point $coordinates */
//         $coordinates = Utils::getPointFromCoordinates($query);

//         // if not a valid point error
//         if (!$coordinates instanceof \Fleetbase\LaravelMysqlSpatial\Types\Point) {
//             return response()->error('Invalid coordinates provided.');
//         }

//         // get results
//         $results = Geocoder::reverse($coordinates->getLat(), $coordinates->getLng())->get();

//         if ($results->count()) {
//             if ($single) {
//                 $googleAddress = $results->first();

//                 return response()->json(Place::createFromGoogleAddress($googleAddress));
//             }

//             return response()->json(
//                 $results->map(
//                     function ($googleAddress) {
//                         return Place::createFromGoogleAddress($googleAddress);
//                     }
//                 )
//                     ->values()
//                     ->toArray()
//             );
//         }

//         return response()->json([]);
//     }

//     /**
//      * Geocodes the given query and returns the results as JSON.
//      *
//      * @param Request $request the HTTP request object
//      *
//      * @return \Illuminate\Http\Response the JSON response with the geocoded results
//      */
//     public function geocode(Request $request)
//     {
//         $query = $request->input('query');
//         $single = $request->boolean('single');

//         if (is_array($query)) {
//             return $this->reverse($request);
//         }

//         // lookup
//         $results = Geocoder::geocode($query)->get();

//         if ($results->count()) {
//             if ($single) {
//                 $googleAddress = $results->first();

//                 return response()->json(Place::createFromGoogleAddress($googleAddress));
//             }

//             return response()->json(
//                 $results->map(
//                     function ($googleAddress) {
//                         return Place::createFromGoogleAddress($googleAddress);
//                     }
//                 )
//                     ->values()
//                     ->toArray()
//             );
//         }

//         return response()->json([]);
//     }
// }



// Modified code using OpenStreetMap Nominatim API

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
// use Geocoder\Laravel\Facades\Geocoder; // Commented out original Geocoder facade
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocoderController extends Controller
{
    /**
     * Reverse geocodes the given coordinates and returns the results as JSON.
     *
     * @param Request $request the HTTP request object
     *
     * @return \Illuminate\Http\Response the JSON response with the geocoded results
     */
    public function reverse(Request $request)
    {
        $query = $request->input('coordinates', $request->input('query'));
        $single = $request->boolean('single');

        Log::info('Received reverse geocoding request', ['query' => $query, 'single' => $single]);
        echo "Received reverse geocoding request with query: $query, single: $single\n";

        /** @var \Fleetbase\LaravelMysqlSpatial\Types\Point $coordinates */
        $coordinates = Utils::getPointFromCoordinates($query);

        // if not a valid point error
        if (!$coordinates instanceof \Fleetbase\LaravelMysqlSpatial\Types\Point) {
            Log::error('Invalid coordinates provided.', ['query' => $query]);
            echo "Invalid coordinates provided.\n";
            return response()->json(['error' => 'Invalid coordinates provided.'], 400);
        }

        // OpenStreetMap Nominatim API request
        Log::info('Making request to OpenStreetMap Nominatim API', ['lat' => $coordinates->getLat(), 'lon' => $coordinates->getLng()]);
        echo "Making request to OpenStreetMap Nominatim API with lat: {$coordinates->getLat()}, lon: {$coordinates->getLng()}\n";

        $response = Http::get('https://nominatim.openstreetmap.org/reverse', [
            'lat' => $coordinates->getLat(),
            'lon' => $coordinates->getLng(),
            'format' => 'jsonv2'
        ]);

        if ($response->successful()) {
            Log::info('Received successful response from OpenStreetMap Nominatim API', ['response' => $response->json()]);
            echo "Received successful response from OpenStreetMap Nominatim API.\n";

            $result = $this->formatNominatimResponse($response->json());

            if ($single) {
                return response()->json($result);
            }

            return response()->json([$result]);
        }

        Log::error('Failed to receive a successful response from OpenStreetMap Nominatim API');
        echo "Failed to receive a successful response from OpenStreetMap Nominatim API.\n";

        return response()->json([]);

        // Original code using Geocoder
        // $results = Geocoder::reverse($coordinates->getLat(), $coordinates->getLng())->get();
        // if ($results->count()) {
        //     if ($single) {
        //         $googleAddress = $results->first();
        //         return response()->json(Place::createFromGoogleAddress($googleAddress));
        //     }
        //     return response()->json(
        //         $results->map(
        //             function ($googleAddress) {
        //                 return Place::createFromGoogleAddress($googleAddress);
        //             }
        //         )->values()->toArray()
        //     );
        // }
        // return response()->json([]);
    }

    /**
     * Geocodes the given query and returns the results as JSON.
     *
     * @param Request $request the HTTP request object
     *
     * @return \Illuminate\Http\Response the JSON response with the geocoded results
     */
    public function geocode(Request $request)
    {
        $query = $request->input('query');
        $single = $request->boolean('single');

        Log::info('Received geocoding request', ['query' => $query, 'single' => $single]);
        echo "Received geocoding request with query: $query, single: $single\n";

        if (is_array($query)) {
            return $this->reverse($request);
        }

        // OpenStreetMap Nominatim API request
        Log::info('Making request to OpenStreetMap Nominatim API', ['query' => $query]);
        echo "Making request to OpenStreetMap Nominatim API with query: $query\n";

        $response = Http::get('https://nominatim.openstreetmap.org/search', [
            'q' => $query,
            'format' => 'jsonv2'
        ]);

        if ($response->successful()) {
            Log::info('Received successful response from OpenStreetMap Nominatim API', ['response' => $response->json()]);
            echo "Received successful response from OpenStreetMap Nominatim API.\n";

            $results = collect($response->json())->map(function ($item) {
                return $this->formatNominatimResponse($item);
            });

            if ($single) {
                return response()->json($results->first());
            }

            return response()->json($results);
        }

        Log::error('Failed to receive a successful response from OpenStreetMap Nominatim API');
        echo "Failed to receive a successful response from OpenStreetMap Nominatim API.\n";

        return response()->json([]);

        // Original code using Geocoder
        // $results = Geocoder::geocode($query)->get();
        // if ($results->count()) {
        //     if ($single) {
        //         $googleAddress = $results->first();
        //         return response()->json(Place::createFromGoogleAddress($googleAddress));
        //     }
        //     return response()->json(
        //         $results->map(
        //             function ($googleAddress) {
        //                 return Place::createFromGoogleAddress($googleAddress);
        //             }
        //         )->values()->toArray()
        //     );
        // }
        // return response()->json([]);
    }

    /**
     * Formats the Nominatim response to the required format.
     *
     * @param array $data The response data from Nominatim
     * 
     * @return array The formatted response
     */
    private function formatNominatimResponse(array $data)
    {
        Log::info('Formatting Nominatim response', ['data' => $data]);
        echo "Formatting Nominatim response.\n";

        return [
            'street1' => $data['address']['road'] ?? '',
            'postal_code' => $data['address']['postcode'] ?? '',
            'neighborhood' => $data['address']['neighbourhood'] ?? '',
            'city' => $data['address']['city'] ?? ($data['address']['town'] ?? ($data['address']['village'] ?? '')),
            'building' => $data['address']['house_number'] ?? '',
            'country' => strtoupper($data['address']['country_code'] ?? ''),
            'location' => [
                'type' => 'Point',
                'coordinates' => [
                    (float) $data['lon'],
                    (float) $data['lat']
                ]
            ],
            'country_name' => $data['address']['country'] ?? '',
            'address' => strtoupper($data['display_name'] ?? ''),
            'address_html' => strtoupper($data['display_name'] ?? '')
        ];
    }
}
