<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\PlaceExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\Geocoding;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\Internal\BulkDeleteRequest;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
// additions
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class PlaceController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'place';

    // /**
    //  * Quick search places for selection.
    //  *
    //  * @return \Illuminate\Http\Response
    //  */
    // public function search(Request $request)
    // {
    //     $searchQuery = $request->searchQuery();
    //     $limit       = $request->input('limit', 30);
    //     $geo         = $request->boolean('geo');
    //     $latitude    = $request->input('latitude');
    //     $longitude   = $request->input('longitude');

    //     $query = Place::where('company_uuid', session('company'))
    //         ->whereNull('deleted_at')
    //         ->search($searchQuery);

    //     if ($latitude && $longitude) {
    //         $point = new Point($latitude, $longitude);
    //         $query->orderByDistanceSphere('location', $point, 'asc');
    //     } else {
    //         $query->orderBy('name', 'desc');
    //     }

    //     if ($limit) {
    //         $query->limit($limit);
    //     }

    //     $results = $query->get();

    //     if ($geo) {
    //         if ($searchQuery) {
    //             try {
    //                 $geocodingResults = Geocoding::query($searchQuery, $latitude, $longitude);

    //                 foreach ($geocodingResults as $result) {
    //                     $results->push($result);
    //                 }
    //             } catch (\Throwable $e) {
    //                 return response()->error($e->getMessage());
    //             }
    //         } elseif ($latitude && $longitude) {
    //             try {
    //                 $geocodingResults = Geocoding::reverseFromCoordinates($latitude, $longitude, $searchQuery);

    //                 foreach ($geocodingResults as $result) {
    //                     $results->push($result);
    //                 }
    //             } catch (\Throwable $e) {
    //                 return response()->error($e->getMessage());
    //             }
    //         }
    //     }

    //     return response()->json($results)->withHeaders(['Cache-Control' => 'no-cache']);
    // }


    // _______________________Modified Code, OSM____________________________

    /**
     * Quick search places for selection.
     *
     * @return \Illuminate\Http\Response
     */
    public function search(Request $request)
    {
        $latitude  = $request->input('latitude');
        $longitude = $request->input('longitude');
        $query     = $request->input('query');
        $limit     = $request->input('limit', 30);
        $geo       = $request->boolean('geo');

        // Prioritize search by lat/long if provided
        if ($latitude && $longitude) {
            $results = $this->searchByLatLng($latitude, $longitude, $limit);

            // If no results found by lat/long, fallback to search by query
            if ($results->isEmpty()) {
                $results = $this->searchByQuery($query, $limit);
            }
        } else {
            $results = $this->searchByQuery($query, $limit);
        }

        try {
            // Internal search
            $internalResultsQuery = Place::where('company_uuid', session('company'))
                ->whereNull('deleted_at')
                ->search($query)
                ->orderBy('name', 'desc');

            if ($limit) {
                $internalResultsQuery = $internalResultsQuery->limit($limit);
            }

            $internalResults = $internalResultsQuery->get();
            $results = $results->merge($internalResults);
        } catch (\Exception $e) {
            // Log the error
            Log::error('Database Query Error:', ['message' => $e->getMessage()]);
            // Continue with OSM results only
        }
        
        Log::info('Search Results:', $results->toArray());

        return response()->json($results);
    }

    private function searchByLatLng($latitude, $longitude, $limit)
    {
        $url = "https://nominatim.openstreetmap.org/reverse?lat=$latitude&lon=$longitude&format=jsonv2&limit=$limit";
        return $this->processGeocodingResponse($url);
    }

    private function searchByQuery($query, $limit)
    {
        if (!$query) {
            return collect();
        }

        $url = "https://nominatim.openstreetmap.org/search.php?q=$query&format=jsonv2&limit=$limit";
        return $this->processGeocodingResponse($url);
    }

    private function processGeocodingResponse($url)
    {
        try {
            $client = new Client();
            $response = $client->request('GET', $url);
            $geocodingResults = json_decode($response->getBody(), true);

            $results = collect();
            if (isset($geocodingResults[0])) {
                foreach ($geocodingResults as $result) {
                    $parsedAddress = $this->parseDisplayName($result['display_name']);
                    $formattedResult = [
                        'street1' => $parsedAddress['address'],
                        'postal_code' => $parsedAddress['postal_code'],
                        'neighborhood' => null,
                        'city' => $parsedAddress['city'],
                        'building' => null,
                        'country' => null,
                        'location' => [
                            'type' => 'Point',
                            'coordinates' => [
                                (float) $result['lon'],
                                (float) $result['lat'],
                            ],
                        ],
                        'country_name' => $parsedAddress['country_name'],
                        'address' => strtoupper($result['display_name']),
                        'address_html' => strtoupper($result['display_name']),
                    ];
                    $results->push($formattedResult);
                }
            }
            return $results;
        } catch (RequestException $e) {
            Log::error('Geocoding API Error:', ['message' => $e->getMessage()]);
            return collect();
        }
    }

    private function parseDisplayName($displayName)
    {
        $parts = explode(',', $displayName);
        $numParts = count($parts);

        $country_name = trim($parts[$numParts - 1] ?? '');
        $postal_code = trim($parts[$numParts - 2] ?? '');
        $city = trim($parts[$numParts - 3] ?? '');

        // Combining the rest of the parts to form the address
        $address = implode(',', array_slice($parts, 0, $numParts - 3));

        return [
            'country_name' => $country_name,
            'postal_code' => $postal_code,
            'city' => $city,
            'address' => $address,
        ];
    }


    // ______________________________________________________________________

    /**
     * Search using geocoder for addresses.
     *
     * @return \Illuminate\Http\Response
     */
    public function geocode(ExportRequest $request)
    {
        $searchQuery = $request->searchQuery();
        $latitude    = $request->input('latitude', false);
        $longitude   = $request->input('longitude', false);
        $results     = collect();

        if ($searchQuery) {
            try {
                $geocodingResults = Geocoding::query($searchQuery, $latitude, $longitude);

                foreach ($geocodingResults as $result) {
                    $results->push($result);
                }
            } catch (\Throwable $e) {
                return response()->error($e->getMessage());
            }
        } elseif ($latitude && $longitude) {
            try {
                $geocodingResults = Geocoding::reverseFromCoordinates($latitude, $longitude, $searchQuery);

                foreach ($geocodingResults as $result) {
                    $results->push($result);
                }
            } catch (\Throwable $e) {
                return response()->error($e->getMessage());
            }
        }

        return response()->json($results)->withHeaders(['Cache-Control' => 'no-cache']);
    }

    /**
     * Export the places to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public function export(ExportRequest $request)
    {
        $format   = $request->input('format', 'xlsx');
        $fileName = trim(Str::slug('places-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new PlaceExport(), $fileName);
    }

    /**
     * Bulk deletes resources.
     *
     * @return \Illuminate\Http\Response
     */
    public function bulkDelete(BulkDeleteRequest $request)
    {
        $ids = $request->input('ids', []);

        if (!$ids) {
            return response()->error('Nothing to delete.');
        }

        /**
         * @var \Fleetbase\Models\Place
         */
        $count   = Place::whereIn('uuid', $ids)->count();
        $deleted = Place::whereIn('uuid', $ids)->delete();

        if (!$deleted) {
            return response()->error('Failed to bulk delete places.');
        }

        return response()->json(
            [
                'status'  => 'OK',
                'message' => 'Deleted ' . $count . ' places',
            ],
            200
        );
    }

    /**
     * Get all avatar options for an vehicle.
     *
     * @return \Illuminate\Http\Response
     */
    public function avatars()
    {
        $options = Place::getAvatarOptions();

        return response()->json($options);
    }
}
