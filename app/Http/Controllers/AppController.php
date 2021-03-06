<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use DateTime;
use App\App;
use App\UserHasApp;
use App\Helpers\AppRestrictionManager;
use App\Helpers\AppTimeCalculator;
use App\Helpers\AppTimeStorage;
use Illuminate\Support\Facades\DB;

class AppController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
 
    public function listApps(Request $request){
        $request_user = $request->user;
        $apps = App::All('id','name', 'logo');
        
        if(empty($apps)){
            $apps = array('error_code' => 400, 'error_msg' => 'No hay lessons encontrados');
        }else {
            return response()->json($apps, 200);
        }
    }

    public function store(Request $request)
    {   
        $app = new App();
        $app->logo = $request->logo;
        $app->name = $request->name;
        $app->save();

        return response()->json(["message" => "new app stored"], 200);
    }
    
    public function store_apps_list(Request $request)
    {        
        $lines = explode("\n", $request->csv);
        $array_csv = [];
        
        foreach ($lines as $line) {
            $array_csv[] = str_getcsv($line);
        }        

        foreach ($array_csv as $key => $line) {           

            if($key != 0)
            {
                $is_app_repeated = App::where('name', '=', $line[1])->first();
                
                if($is_app_repeated == null){

                    $app = new App();
                    $app->logo = $line[0];               
                    $app->name = $line[1];                
                    $app->save();

                }

            }     
                       
        }

        $apps = App::select('id','name','logo')->get();        

        return response()->json(

            $apps                 

        , 200);

    }
   
    public function store_apps_data(Request $request)
    {
        $request_user = $request->user; 
        $lines = explode("\n", $request->csv);      
        $array_csv = [];
        
        foreach ($lines as $line) {
            
            $array_csv[] = str_getcsv($line);
        
        }        

        foreach ($array_csv as $key => $line) {
                              
            if($key != 0)
            {
                $name = $line[1];             
                $app = App::where('name', '=', $name)->first();
                
                $request_user->apps()->attach($app->id, [

                    'date' => $line[0], 
                    'event' => $line[2],                      
                    'latitude' => $line[3],
                    'longitude' => $line[4],
        
                ]); 

            }
    
        }

    }
    
    public function total_time_used (Request $request, $id){

        $request_user = $request->user;          
        
        $app_entries = $request_user->apps()->wherePivot('app_id', $id)->get();
        $app_entry = $request_user->apps()->wherePivot('app_id', $id)->select('name')->first();
        
        $app_time_calculator = new AppTimeCalculator($app_entries);
        $total_usage_time_in_seconds = $app_time_calculator->app_total_hours();
        $total_usage_time = Carbon::createFromTimestampUTC($total_usage_time_in_seconds)->toTimeString();

        return response()->json([
           
            "app_name" => $app_entry->name,
            "total_usage_time" => $total_usage_time,  

        ], 200);

    }

    public function apps_statistics(Request $request)
    {
        $request_user = $request->user;        
        $apps_names = App::select('name')->get();
       
        $apps_time_averages = [];      
      
        foreach ($apps_names as $app_name)
        {           
            $app_entries = $request_user->apps()->where('name', '=', $app_name["name"])->get();
            $app_time_calculator = new AppTimeCalculator($app_entries);
            $total_usage_time_in_seconds = $app_time_calculator->app_total_hours();
            $total_usage_time = Carbon::createFromTimestampUTC($total_usage_time_in_seconds)->toTimeString();                    
            
            $total_usage_time_in_milliseconds  = $total_usage_time_in_seconds * 1000;
            
            $day_average = Carbon::createFromTimestampMs($total_usage_time_in_milliseconds / 365)->format('H:i:s.u');            
            $week_average = Carbon::createFromTimestampMs($total_usage_time_in_milliseconds / 52)->format('H:i:s.u');
            $month_average = Carbon::createFromTimestampMs($total_usage_time_in_milliseconds / 12)->format('H:i:s.u');
          
            $apps_time_averages[] = new AppTimeStorage($app_name["name"], $total_usage_time, $day_average);

        }

        return response()->json(
            
            $apps_time_averages           
                       
        , 200);

    }

    public function time_per_day_used (Request $request, $id)
    {
        $request_user = $request->user;        
        $app_entries = $request_user->apps;
        $app_entries_by_date = $app_entries->where("id", "=", $id)->groupBy(function($item) {
            $new_date = Carbon::parse($item->pivot->date);
            return $new_date->format('Y-m-d');
        });
        $app_dates_per_day = [];

        foreach ($app_entries_by_date as $key => $entry) {

            $app_time_calculator = new AppTimeCalculator($entry);        
            $total_usage_time_in_seconds = $app_time_calculator->app_total_hours();
            $total_usage_time = Carbon::createFromTimestampUTC($total_usage_time_in_seconds)->toTimeString();
            $app_dates_per_day[$key] = $total_usage_time;

        }

        return response()->json(

            $app_dates_per_day 

        , 200);
    
    }
 
    public function apps_restrictions(Request $request)
    {
        $request_user = $request->user;
        $restrictions = DB::table('users_restrict_apps')->where('user_id', '=', $request_user->id)->get();                      
        $apps_restrictions = [];
        
        foreach ($restrictions as $app_restricion)
        {
            $app = DB::table('apps')->where('id', '=', $app_restricion->app_id)->first();
            $apps_restrictions[] = new AppRestrictionManager($app->name, $app_restricion->usage_from_hour, $app_restricion->usage_to_hour, $app_restricion->maximum_usage_time);
        
        }

        return response()->json(

            $apps_restrictions

        , 200);

    }
    public function apps_usage_range(Request $request, $date)
    {
        $request_user = $request->user;
        $apps = App::all();
        $apps_ranges = [];

        foreach ($apps as $app)
        {
            $app_initial_range = DB::table('users_have_apps')
                                ->where('user_id', '=', $request_user->id)
                                ->where('app_id', '=', $app->id)
                                ->whereDate('date', '=', $date)
                                ->where('event', '=', 'opens')
                                ->first(); 

            $app_finish_range = DB::table('users_have_apps')
                                ->where('user_id', '=', $request_user->id)
                                ->where('app_id', '=', $app->id)
                                ->whereDate('date', '=', $date)
                                ->where('event', '=', 'closes')
                                ->orderBy('date', 'desc')
                                ->first();

            $app_opens_no_closes = DB::table('users_have_apps')
                                ->where('user_id', '=', $request_user->id)
                                ->where('app_id', '=', $app->id)
                                ->whereDate('date', '=', $date)
                                ->where('event', '=', 'opens')
                                ->orderBy('date', 'desc')
                                ->first();

            if($app_initial_range == NULL && $app_finish_range == NULL && $app_opens_no_closes == NULL)
            {
                $apps_ranges[] = new AppRestrictionManager($app->name, "Sin tiempo de uso", "Sin tiempo de uso", "Sin tiempo de uso");
            
            }else if($app_initial_range == NULL && $app_opens_no_closes == NULL)
            {
                $app_finish_range_hour = Carbon::parse($app_finish_range->date)->format('H:i:s');
                $app_entries = $request_user->apps()->where('name', '=', $app->name)->whereDate('date', '=', $date)->get();
                $app_time_calculator = new AppTimeCalculator($app_entries);
                $total_usage_time_in_seconds = $app_time_calculator->app_total_hours();
                $total_usage_time = Carbon::createFromTimestampUTC($total_usage_time_in_seconds)->toTimeString();
                $apps_ranges[] = new AppRestrictionManager($app->name, "00:00:00", $app_finish_range_hour, $total_usage_time);

            }else{

                if($app_finish_range->date < $app_opens_no_closes->date){

                    $app_initial_range_hour = Carbon::parse($app_initial_range->date)->format('H:i:s');
                    $app_entries = $request_user->apps()->where('name', '=', $app->name)->whereDate('date', '=', $date)->get();
                    $app_time_calculator = new AppTimeCalculator($app_entries);
                    $total_usage_time_in_seconds = $app_time_calculator->app_total_hours();
                    $total_usage_time = Carbon::createFromTimestampUTC($total_usage_time_in_seconds)->toTimeString();
                    $apps_ranges[] = new AppRestrictionManager($app->name, $app_initial_range_hour, "00:00:00", $total_usage_time);

                }else{

                    $app_initial_range_hour = Carbon::parse($app_initial_range->date)->format('H:i:s');
                    $app_finish_range_hour = Carbon::parse($app_finish_range->date)->format('H:i:s');
                    $app_entries = $request_user->apps()->where('name', '=', $app->name)->whereDate('date', '=', $date)->get();
                    $app_time_calculator = new AppTimeCalculator($app_entries);
                    $total_usage_time_in_seconds = $app_time_calculator->app_total_hours();
                    $total_usage_time = Carbon::createFromTimestampUTC($total_usage_time_in_seconds)->toTimeString();
                    $apps_ranges[] = new AppRestrictionManager($app->name, $app_initial_range_hour, $app_finish_range_hour, $total_usage_time);

                }
            }
        }

        return response()->json($apps_ranges, 200);

    }

    public function apps_coordinates(Request $request)
    {
        $request_user = $request->user;        
        $apps_names = App::select('name')->get();
        $apps_coordinates = [];
        $apps_coordinates_groups = [];
 
        foreach ($apps_names as $app_name)
        {
            $app_entry = $request_user->apps()->where('name', '=', $app_name["name"])->latest('date')->first();
            $app_time_storage = new AppTimeStorage();
            $apps_coordinates[] = $app_time_storage->create()->set_coordinates($app_entry->name, $app_entry->pivot->latitude, $app_entry->pivot->longitude);

        }

        foreach ($apps_coordinates as $app_coordinates_entry)
        {
            $is_found = false;

            foreach ($apps_coordinates_groups as $new_array_line)
            {        
                if($app_coordinates_entry->latitude == $new_array_line->latitude && $app_coordinates_entry->longitude == $new_array_line->longitude){
              
                    $new_array_line->name .= " " . $app_coordinates_entry->name;
                    $is_found = true;
                    break;

                }

            }

            if($is_found == false){

                $apps_coordinates_groups[] = $app_coordinates_entry;

            }

        }

        return response()->json(

            $apps_coordinates_groups

        , 200);
       
    }

   
    public function save_used_time(Request $request, $id)
    {
        $request_user = $request->user;
        $app_entries = $request_user->apps()->wherePivot('app_id', $id)->get();
        $app_entry = $request_user->apps()->wherePivot('app_id', $id)->first();   
        $app_entries_lenght = count($app_entries);
        $total_time_in_seconds = 0;

        if($app_entries[0]->pivot->event == "closes")
        {                                  
            $date_format = Carbon::createFromFormat('Y-m-d H:i:s', $app_entries[0]->pivot->date)->format('Y-m-d'); 
            $date_format_at_midnight = $date_format . ' 00:00:00';
            $date_from_midnight = Carbon::parse($date_format_at_midnight);
            $date_hour = Carbon::createFromFormat('Y-m-d H:i:s', $app_entries[0]->pivot->date);
            $time_diff_from_midnight_in_seconds = $date_from_midnight->diffInSeconds($date_hour);
            $total_time_in_seconds = $total_time_in_seconds + $time_diff_from_midnight_in_seconds;            

            for ($x = 1; $x <= $app_entries_lenght - 1; $x++) {

                $have_both_hours = true;
    
                if($app_entries[$x]->pivot->event == "opens")
                {
                    $from_hour = Carbon::createFromFormat('Y-m-d H:i:s', $app_entries[$x]->pivot->date);                
                    $from_hour_format = Carbon::createFromFormat('Y-m-d H:i:s', $app_entries[$x]->pivot->date)->format('Y-m-d'); 
                    $from_hour_format_to_midnight = $from_hour_format . ' 23:59:59';
                    $today_to_midnight = Carbon::parse($from_hour_format_to_midnight);
                    $time_diff_till_midnight = $from_hour->diffInSeconds($today_to_midnight);
                    $total_time_in_seconds = $total_time_in_seconds + $time_diff_till_midnight;                             
                    $have_both_hours = false;                
                    
                }else if($app_entries[$x]->pivot->event == "closes"){
    
                    $total_time_in_seconds = $total_time_in_seconds - $time_diff_till_midnight;
                    $to_hour = Carbon::createFromFormat('Y-m-d H:i:s', $app_entries[$x]->pivot->date);                        
                    $have_both_hours = true;
    
                }
                
                if($have_both_hours)
                {
                    $total_time_in_seconds += $from_hour->diffInSeconds($to_hour);
    
                }           
    
            }

        }else{

            for ($x = 0; $x <= $app_entries_lenght - 1; $x++) {

                $have_both_hours = true;
    
                if($app_entries[$x]->pivot->event == "opens")
                {
                    $from_hour = Carbon::createFromFormat('Y-m-d H:i:s', $app_entries[$x]->pivot->date);                
                    $from_hour_format = Carbon::createFromFormat('Y-m-d H:i:s', $app_entries[$x]->pivot->date)->format('Y-m-d'); 
                    $from_hour_format_to_midnight = $from_hour_format . ' 23:59:59';
                    $today_to_midnight = Carbon::parse($from_hour_format_to_midnight);
                    $time_diff_till_midnight = $from_hour->diffInSeconds($today_to_midnight);
                    $total_time_in_seconds = $total_time_in_seconds + $time_diff_till_midnight;                             
                    $have_both_hours = false;                
                    
                }else if($app_entries[$x]->pivot->event == "closes"){
    
                    $total_time_in_seconds = $total_time_in_seconds - $time_diff_till_midnight;
                    $to_hour = Carbon::createFromFormat('Y-m-d H:i:s', $app_entries[$x]->pivot->date);                        
                    $have_both_hours = true;
    
                }
                
                if($have_both_hours)
                {
                    $total_time_in_seconds += $from_hour->diffInSeconds($to_hour);
    
                }           
    
            }

        }

        $total_usage_time = Carbon::createFromTimestampUTC($total_time_in_seconds)->toTimeString();

        return response()->json([

            "app_name" => $app_entry->name,
            "total_usage_time" => $total_usage_time,  

        ]);

    }

}
