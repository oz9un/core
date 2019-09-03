<?php

namespace App\Http\Controllers\Widgets;

use App\Extension;
use App\Permission;
use App\Server;
use App\Token;
use App\Widget;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OneController extends Controller
{
    public function one()
    {
        $widget = Widget::find(\request('widget_id'));
        if(!$widget){
            return respond(__("Widget Bulunamadı"),201);
        }
        $extension =  Extension::one($widget->extension_id);
        $extensionData = json_decode(file_get_contents(env("EXTENSIONS_PATH") .strtolower(extension($widget->extension_id)->name) . DIRECTORY_SEPARATOR . "db.json"),true);
        foreach ($extensionData["database"] as $item){
            if(!DB::table("user_settings")->where([
                "user_id" => auth()->user()->id,
                "server_id" => $widget->server_id,
                "extension_id" => $extension->id,
                "name" => $item["variable"]
            ])->exists()){
                return respond(__("Eklenti ayarları eksik.") . " <a href='".url('ayarlar/'.$extension->id.'/'.$widget->server_id)."'>".__("Ayarlara Git.")."</a>", 400);
            }
        }
        $server = Server::find($widget->server_id);
        request()->request->add(['server' => $server]);
        request()->request->add(['widget' => $widget]);
        request()->request->add(['extension_id' => $extension->id]);
        request()->request->add(['extension' => $extension]);
        $command = self::generateSandboxCommand($server, $extension, "", auth()->id(), "null", "null", $widget->function);
        $output = shell_exec($command);
        if(!$output){
            return respond(__("Widget Hiçbir Veri Döndürmedi"), 400);
        }
        $output_json = json_decode($output, true);
        if(!isset($output_json)){
          return respond(__("Boş json nesnesi."), 400);
        }
        return respond($output_json['message'], $output_json['status']);
    }

    public function remove()
    {
        $widget = Widget::find(\request('widget_id'));
        $widget->delete();
        return respond(__("Başarıyla silindi"));
    }

    public function update()
    {
        $widget = Widget::find(\request('widget_id'));
        $widget->update([
            "server_id" => \request('server_id'),
            "extension_id" => \request('extension_id'),
            "title" => \request('title'),
            "function_name" => \request('function_name')
        ]);
        return respond(__("Başarıyla güncellendi."));
    }

    public function extensions()
    {
        $extensions = [];
        foreach (server()->extensions() as $extension){
            $extensions[$extension->id] = $extension->name;
        }
        return $extensions;
    }

    public function widgetList()
    {
        $extension = json_decode(file_get_contents(env("EXTENSIONS_PATH") .strtolower(extension()->name) . DIRECTORY_SEPARATOR . "db.json"),true);
        return $extension["widgets"];
    }

    private function generateSandboxCommand($serverObj, $extensionObj, $extension_id, $user_id, $outputs, $viewName, $functionName,$extensionDb = null)
    {
        if(!$extension_id){
            $extension_id = extension()->id;
        }
        $functions = env('EXTENSIONS_PATH') . strtolower($extensionObj["name"]) . "/views/functions.php";

        $combinerFile = env('SANDBOX_PATH') . "index.php";

        $server = json_encode($serverObj->toArray());

        $extension = json_encode($extensionObj);

        if($extensionDb == null){
            $settings = DB::table("user_settings")->where([
                "user_id" => $user_id,
                "server_id" => server()->id,
                "extension_id" => extension()->id
            ]);
            $extensionDb = [];
            foreach ($settings->get() as $setting){
                $key = env('APP_KEY') . auth()->user()->id . extension()->id . server()->id;
                $decrypted = openssl_decrypt($setting->value,'aes-256-cfb8',$key);
                $extensionDb[$setting->name] = base64_decode(substr($decrypted,16));
            }
        }
        $extensionDb = json_encode($extensionDb);

        $outputsJson = json_encode($outputs);

        $request = request()->all();
        unset($request["permissions"]);
        unset($request["extension"]);
        unset($request["server"]);
        unset($request["script"]);
        unset($request["server_id"]);
        $request = json_encode($request);

        $apiRoute = route('extension_function_api', [
            "extension_id" => extension()->id,
            "function_name" => ""
        ]);

        $navigationRoute = route('extension_server_route', [
            "server_id" => $serverObj->id,
            "extension_id" => extension()->id,
            "city" => $serverObj->city,
            "unique_code" => ""
        ]);

        $token = Token::create($user_id);

        if(!auth()->user()->isAdmin()){
            $permissions = Permission::where('user_id',auth()->user()->id)
                ->where('function','like',strtolower(extension()->name). '%')->pluck('function')->toArray();
            for($i = 0 ;$i< count($permissions); $i++){
                $permissions[$i] = explode('_',$permissions[$i])[1];
            }
            $permissions = json_encode($permissions);
        }else{
            $permissions = "admin";
        }
        $array = [$functions,strtolower(extension()->name),
            $viewName,$server,$extension,$extensionDb,$outputsJson,$request,$functionName,
            $apiRoute,$navigationRoute,$token,$extension_id,$permissions];
        $encrypted = openssl_encrypt(Str::random() . base64_encode(json_encode($array)),
            'aes-256-cfb8',shell_exec('cat ' . env('KEYS_PATH') . DIRECTORY_SEPARATOR . extension()->id),
            0,Str::random());
        $keyPath = env('KEYS_PATH') . DIRECTORY_SEPARATOR . extension()->id;
        $command = "sudo runuser " . clean_score(extension()->id) .
            " -c 'timeout 30 /usr/bin/php -d display_errors=on $combinerFile $keyPath $encrypted'";
        return $command;
    }

}
