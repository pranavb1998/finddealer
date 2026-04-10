<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Country;
use DB;
use App\Models\PageBanner;

class FindDealerController extends Controller
{
    private function sanitizeFloat($value)
    {
        if ($value === null || $value === '') {
            return '';
        }

        return is_numeric($value) ? (float) $value : '';
    }

    private function sanitizeLatitude($value)
    {
        $lat = $this->sanitizeFloat($value);
        if ($lat === '') {
            return '';
        }

        return ($lat >= -90 && $lat <= 90) ? $lat : '';
    }

    private function sanitizeLongitude($value)
    {
        $long = $this->sanitizeFloat($value);
        if ($long === '') {
            return '';
        }

        return ($long >= -180 && $long <= 180) ? $long : '';
    }

    private function sanitizeRadius($value)
    {
        $radius = $this->sanitizeFloat($value);
        if ($radius === '' || $radius <= 0) {
            return 100;
        }

        // Keep query cost bounded to avoid abuse while preserving expected UX.
        return min($radius, 500);
    }

    private function sanitizeCountryCode($value)
    {
        $code = strtoupper(trim((string) $value));
        return preg_match('/^[A-Z]{2,5}$/', $code) ? $code : '';
    }

    private function sanitizeRouteName($value)
    {
        $route = trim((string) $value);
        return preg_match('/^[A-Za-z0-9._-]{1,120}$/', $route) ? $route : '';
    }

    private function sanitizeText($value)
    {
        return trim(strip_tags((string) $value));
    }

    private function sanitizeIntArray($value)
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($item) {
            return is_numeric($item) ? (int) $item : null;
        }, $value), function ($item) {
            return $item !== null;
        }));
    }

    private function allowlistIntArray(array $value, array $allowed)
    {
        if (empty($value) || empty($allowed)) {
            return [];
        }

        $allowedLookup = array_flip(array_map('intval', $allowed));
        return array_values(array_filter($value, function ($item) use ($allowedLookup) {
            return isset($allowedLookup[(int) $item]);
        }));
    }
	
	 public function index(Request $request,$country){

        $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'src_latitude' => 'nullable|numeric|between:-90,90',
            'src_longitude' => 'nullable|numeric|between:-180,180',
            'radius' => 'nullable|numeric|min:1|max:500',
            'type' => 'nullable|array',
            'type.*' => 'integer|in:1,2',
            'dealer_ship_type' => 'nullable|array',
            'dealer_ship_type.*' => 'integer',
            'location' => 'nullable|string|max:255',
        ]);
        
        $routeName = $this->sanitizeRouteName(optional($request->route())->getName());
        //$pageBanner= PageBanner::whereRaw('find_in_set("'.$routeName.'",page_route_name)')->first();
        $pageBanner = null;
        if ($routeName !== '') {
            $pageBanner = PageBanner::whereRaw('find_in_set(?, page_route_name)', [$routeName])->first();
        }

        $countryCode = $this->sanitizeCountryCode($country);
        if ($countryCode === '') {
            abort(404);
        }

        $country = Country::where('code', $countryCode)->where('status', 1)->first(); 
        if (!$country) {
            abort(404);
        }
        $dealerShipType = DB::table('dealer_ships')->where('status',1)->whereNull('deleted_at')->get();      
        $allowedDealerShipTypeIds = $dealerShipType->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();
        
        $dealers = [];
		
		$myLatitude = ''; 
		$myLongitude = '';
		$myLocation = '';
		
	
        if(isset($request->all()['latitude']) && $request->all()['latitude'] != '' && isset($request->all()['longitude']) && $request->all()['longitude'] != '' && isset($request->all()['location']) && $request->all()['location'] != '' ){
            $myLatitude = $this->sanitizeLatitude($request->all()['latitude']);
            $myLongitude = $this->sanitizeLongitude($request->all()['longitude']);
            $myLocation = $this->sanitizeText($request->all()['location']);
		}
		
		
		$navigate = '';
		
		$latSearch='';
        $longSearch='';

        if($request->cookie('SMLLat') != '' && $request->cookie('SMLLong') != ''){
            $latSearch = $this->sanitizeLatitude($request->cookie('SMLLat'));
            $longSearch = $this->sanitizeLongitude($request->cookie('SMLLong'));
            } 
		
        if ($request->isMethod('post') || $myLatitude) {
            
            // $myLatitude = ''; 
            // $myLongitude = '';
            
            // if($request->cookie('SMLLat') != '' && $request->cookie('SMLLong') != ''){
                // $myLatitude = $request->cookie('SMLLat');
                // $myLongitude = $request->cookie('SMLLong');
            // }           
           
		   
            $data = $request->all(); 
			
			
            if(isset($data['src_latitude']) && $data['src_latitude'] != '' && isset($data['src_longitude']) && $data['src_longitude'] != ''){
                $myLatitude = $this->sanitizeLatitude($data['src_latitude']);
                $myLongitude = $this->sanitizeLongitude($data['src_longitude']);
				
			}
			
			if(isset($data['location']) && $data['location'] != ''){
                $myLocation = $this->sanitizeText($data['location']);
			}

            $sanitizedTypes = $this->allowlistIntArray($this->sanitizeIntArray($data['type'] ?? []), [1, 2]);
            $sanitizedDealerShipTypes = $this->allowlistIntArray($this->sanitizeIntArray($data['dealer_ship_type'] ?? []), $allowedDealerShipTypeIds);
			
			
            $dealersQuery = DB::table('dealer_forms')->leftJoin('dealer_ships', 'dealer_forms.services', 'dealer_ships.id');
            
            // Always filter by status
            $dealersQuery->where('dealer_forms.status', 1);
            
            // Handle type and services filters
            if(!empty($sanitizedTypes)){
                
                if(!empty($sanitizedDealerShipTypes)){
                    // Both type and services specified - use complex conditional logic
                    $dealersQuery->where(function($query) use ($sanitizedTypes, $sanitizedDealerShipTypes) {
                        if(in_array(1, $sanitizedTypes, true) && in_array(2, $sanitizedTypes, true)){
                            // Both dealer (1) and distributor (2) selected
                            $query->where(function($q) use ($sanitizedDealerShipTypes) {
                                $q->where('type', 1)
                                  ->whereIn('services', $sanitizedDealerShipTypes);
                            })->orWhere('type', 2);
                        } elseif(in_array(1, $sanitizedTypes, true) && !in_array(2, $sanitizedTypes, true)){
                            // Only dealer (1) selected
                            $query->where('type', 1)
                                  ->whereIn('services', $sanitizedDealerShipTypes);
                        } elseif(in_array(2, $sanitizedTypes, true) && !in_array(1, $sanitizedTypes, true)){
                            // Only distributor (2) selected
                            $query->where('type', 2)
                                  ->whereIn('services', $sanitizedDealerShipTypes);
                        }
                    });
                } else {
                    // Only type specified, no services filter
                    $dealersQuery->whereIn('type', $sanitizedTypes);
                }
            }

            // if(isset($data['location']) && trim($data['location']) != ''){
                
                // $location = $data['location'];
                // $dealersQuery->where(function ($query) use ($location){
                // $query->where('state','LIKE',"%{$location}%")
                    // ->orwhere('city','LIKE',"%{$location}%")
                    // ->orwhere('address','LIKE',"%{$location}%");
                // });             
            // }
            
            if($myLatitude != '' && $myLongitude != ''){
                $radius = $this->sanitizeRadius($data['radius'] ?? '');
                $dealersQuery->selectRaw("*,
                                ( 6371  * acos( cos( radians(?) ) *
                                cos( radians( latitude ) )
                                * cos( radians( longitude ) - radians(?)
                                ) + sin( radians(?) ) *
                                sin( radians( latitude ) ) )
                                ) AS distance", [$myLatitude, $myLongitude, $myLatitude]);
                                $dealersQuery->havingRaw("distance < ?", [$radius]);
                                $dealersQuery->orderBy("distance",'asc');                               
            }else{
                $dealersQuery->where('country_id', $country->id);
            }   
            $dealers = $dealersQuery->get();
			
			$navigate  = 1;
			
        }
		
        $dataView = [
            'dealers'    => $dealers,      
            'country'  => $country,     
            'dealerShipType'=>$dealerShipType ,
            'radius' =>  (isset($radius)) ? $radius : '',
            'selectedTypes' => (isset($sanitizedTypes) && $sanitizedTypes) ? $sanitizedTypes : [],
            'selectedServices' => (isset($sanitizedDealerShipTypes) && $sanitizedDealerShipTypes) ? $sanitizedDealerShipTypes : [],
           'location' => $myLocation,  
			'myLatitude' => $myLatitude,
			'myLongitude' => $myLongitude,
            'pageBanner'=>$pageBanner,
			'navigate' => $navigate ,
			'latSearch'=>$latSearch,
            'longSearch'=>$longSearch
        ];
		
        return view('frontend.pages.find-dealer',$dataView);
    }
	
	
    

    
}
