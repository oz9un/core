<?php

namespace App\Http\Controllers\Extension;

use App\Extension;
use App\Permission;
use App\Script;
use App\Server;
use App\Token;
use App\Http\Controllers\Controller;
use App\User;
use Exception;
use function request;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Redirector;
use Illuminate\View\View;

/**
 * Class OneController
 * @package App\Http\Controllers\Extension
 */
class OneController extends Controller
{
    /**
     * @return Illuminate\View\View
     */
    public function renderView()
    {
        // Now that we have server, let's check if required parameters set for extension.
        foreach (extension()->database as $setting) {
            if (
                !array_key_exists(server()->_id, auth()->user()->settings) ||
                !array_key_exists(extension()->_id, auth()->user()->settings[server()->_id]) ||
                !array_key_exists($setting["variable"], auth()->user()->settings[server()->_id][extension()->_id])
            ) {
                system_log(7,"EXTENSION_MISSING_SETTINGS",[
                    "extension_id" => extension()->_id
                ]);
                return redirect(route('extension_server_settings_page', [
                    "server_id" => server()->_id,
                    "extension_id" => extension()->_id
                ]));
            }
        }

        $outputs = [];

        $viewName = (request('unique_code')) ? request('unique_code') : "index";

        $flag = false;
        foreach (extension()->views as $view){
            if($view["name"] == $viewName){
                $flag = true;
                break;
            }
        }

        if(!$flag){
            system_log(7,"EXTENSION_MISSING_PAGE",[
                "extension_id" => extension()->_id
            ]);
            abort(504,"Sayfa bulunamadı");
        }

        if (!is_file(env('EXTENSIONS_PATH') . strtolower(extension()->name) . "/" . $viewName . ".blade.php")) {
            system_log(5,"EXTENSION_MISSING_PAGE",[
                "extension_id" => extension()->_id,
                "page_name" => $viewName
            ]);
            abort(504,"Sayfa bulunamadı");
        }

//        $results = Validator::do(env('EXTENSIONS_PATH') . strtolower(extension()->name) . "/" . $viewName . ".blade.php");

        // Go through each required scripts and run each of them.
        $views = extension()->views;
        foreach ($views as $view) {
            if ($view["name"] == $viewName) {
                $scripts = explode(',', $view["scripts"]);
                if (count($scripts) == 1 && $scripts[0] == "") {
                    break;
                }
                foreach ($scripts as $unique_code) {
                    // Get Script
                    $script = extension()->scripts()->where('unique_code', trim($unique_code))->first();

                    // Check if required script is available or not.
                    if (!$script) {
                        system_log(7,"EXTENSION_MISSING_SCRIPT",[
                            "extension_id" => extension()->_id,
                            "target_name" => trim($unique_code)
                        ]);
                        return respond("Eklenti için gerekli olan betik yüklü değil, lütfen yöneticinizle görüşün.", 404);
                    }

                    if (!Permission::can(auth()->id(), 'script', $script->_id)) {
                        system_log(6,"EXTENSION_NO_PERMISSION",[
                            "extension_id" => extension()->_id,
                            "target_name" => trim($unique_code)
                        ]);
                        abort(403, "Eklenti için yetkiniz var fakat '" . $script->name . "' betiğini çalıştırmak için yetkiniz yok.");
                    }

                    $parameters = "";
                    foreach (explode(',', $script->inputs) as $input) {
                        $parameters = $parameters . " '" . request(explode(':', $input)[0]) . "'";
                    }

                    $output = server()->runScript($script, $parameters);

                    // Decode output and set it into outputs array.
                    $output = str_replace('\n', '', $output);
                    $outputs[trim($unique_code)] = json_decode($output, true);
                }
                break;
            }
        }

        $command = self::generateSandboxCommand(server(), extension(), auth()->user()->settings, auth()->id(), $outputs, $viewName, null);
        $output = shell_exec($command);
        system_log(7,"EXTENSION_RENDER_PAGE",[
            "extension_id" => extension()->_id,
            "server_id" => server()->_id,
            "view" => $viewName
        ]);
        // Return all required parameters.
        return view('extension_pages.server', [
            "view" => $output,
            "command" => $command
        ]);
    }

    /**
     * @return JsonResponse|Response
     */
    public function runFunction()
    {
        // Before Everything, check if it's a function or script.
        if (Script::where('unique_code', request('function_name'))->exists()) {
            $script = Script::where('unique_code', request('function_name'))->first();
            if (!Permission::can(auth()->id(), 'script', $script->_id)) {
                system_log(7,"EXTENSION_NO_PERMISSION",[
                    "extension_id" => extension()->_id,
                    "target_name" => $script->name
                ]);
                abort(403, $script->name . " betiği için yetkiniz yok.");
            }

            $parameters = "";
            foreach (explode(',', $script->inputs) as $input) {
                $parameters = $parameters . " '" . request(explode(':', $input)[0]) . "'";
            }
            return respond(server()->runScript($script, $parameters));
        }
        if (!Permission::can(auth()->id(), "function", strtolower(extension()->name) . "_" . request('function_name'))) {
            system_log(7,"EXTENSION_NO_PERMISSION",[
                "extension_id" => extension()->_id,
                "target_name" => request('function_name')
            ]);
            abort(403, request('function_name') . " için yetkiniz yok.");
        }

        $command = self::generateSandboxCommand(server(), extension(), auth()->user()->settings, auth()->id(), "null", "null", request('function_name'));

        system_log(7,"EXTENSION_RUN",[
            "extension_id" => extension()->_id,
            "server_id" => server()->_id,
            "target_name" => request('function_name')
        ]);
        return shell_exec($command);
    }

    public function internalExtensionApi()
    {
        if ($_SERVER['SERVER_ADDR'] != $_SERVER['REMOTE_ADDR']) {
            system_log(5,"EXTENSION_INTERNAL_NO_PERMISSION",[
                "extension_id" => extension()->_id,
            ]);
            abort(403, 'Not Allowed');
        }
        $token = Token::where('token', request('token'))->first() or abort(403, "Token gecersiz");

        $server = Server::find(request('server_id')) or abort(404, 'Sunucu Bulunamadi');
        if (!Permission::can($token->user_id, 'server', $server->_id)) {
            system_log(7,"EXTENSION_NO_PERMISSION_SERVER",[
                "extension_id" => extension()->_id,
                "server_id" => request('server_id')
            ]);
            return "Sunucu icin yetkiniz yok.";
        }

        $extension = Extension::find(request('extension_id')) or abort(404, 'Eklenti Bulunamadi');
        if (!Permission::can($token->user_id, 'extension', $extension->_id)) {
            system_log(7,"EXTENSION_NO_PERMISSION_SERVER",[
                "extension_id" => extension()->_id,
                "server_id" => request('server_id')
            ]);
            return "Eklenti icin yetkiniz yok.";
        }

        if (!Permission::can($token->user_id, "function", strtolower(extension()->name) . "_" . strtolower(request('target')))) {
            system_log(7,"EXTENSION_NO_PERMISSION",[
                "extension_id" => extension()->_id,
                "target_name" => request('function_name')
            ]);
            return request('target') . " fonksiyonu için yetkiniz yok.";
        }

        $user = User::find($token->user_id);
        $command = self::generateSandboxCommand($server, $extension, $user->settings, $user->_id, "null", "null", request('target'));
        $output = shell_exec($command);

        system_log(7,"EXTENSION_INTERNAL_RUN",[
            "extension_id" => extension()->_id,
            "server_id" => server()->_id,
            "target_name" => request('function_name')
        ]);

        return $output;
    }

    public function internalRunCommandApi()
    {
        if ($_SERVER['SERVER_ADDR'] != $_SERVER['REMOTE_ADDR']) {
            system_log(5,"EXTENSION_INTERNAL_NO_PERMISSION",[
                "extension_id" => extension()->_id,
            ]);
            return 'Not Allowed';
        }
        $token = Token::where('token', request('token'))->first() or abort(403, "Token gecersiz");

        auth()->loginUsingId($token->user_id);

        $server = Server::find(request('server_id')) or abort(404, 'Sunucu Bulunamadi');
        if (!Permission::can($token->user_id, 'server', $server->_id)) {
            system_log(7,"EXTENSION_NO_PERMISSION_SERVER",[
                "extension_id" => extension()->_id,
                "server_id" => request('server_id')
            ]);
            return "Sunucu icin yetkiniz yok.";
        }

        if ($server->type != "linux_ssh" && $server->type != "windows_powershell") {
            system_log(7,"EXTENSION_INTERNAL_RUN_COMMAND_FAILED",[
                "extension_id" => extension()->_id,
                "server_id" => server()->_id
            ]);
            return "Bu sunucuda komut çalıştıramazsınız.";
        }

        request()->request->add(['server' => $server]);
        $output = $server->run(request('command'));

        system_log(7,"EXTENSION_INTERNAL_RUN_COMMAND",[
            "extension_id" => extension()->_id,
            "server_id" => server()->_id
        ]);

        return $output;
    }

    public function internalPutFileApi()
    {
        if ($_SERVER['SERVER_ADDR'] != $_SERVER['REMOTE_ADDR']) {
            system_log(5,"EXTENSION_INTERNAL_NO_PERMISSION",[
                "extension_id" => extension()->_id,
            ]);
            return 'Not Allowed';
        }
        $token = Token::where('token', request('token'))->first() or abort(403, "Token gecersiz");

        auth()->loginUsingId($token->user_id);

        $server = Server::find(request('server_id')) or abort(404, 'Sunucu Bulunamadi');

        if ($server->type != "linux_ssh" && $server->type != "windows_powershell") {
            system_log(7,"EXTENSION_INTERNAL_RUN_COMMAND_FAILED",[
                "extension_id" => extension()->_id,
                "server_id" => server()->_id
            ]);
            return "Bu sunucuda komut çalıştıramazsınız.";
        }

        request()->request->add(['server' => $server]);
        $output = $server->putFile(request('localPath'), request('remotePath'));

        system_log(7,"EXTENSION_INTERNAL_SEND_FILE",[
            "extension_id" => extension()->_id,
            "server_id" => server()->_id,
            "file_name" => request('remotePath'),
        ]);

        return ($output) ? "ok" : "no";
    }

    public function internalGetFileApi()
    {
        if ($_SERVER['SERVER_ADDR'] != $_SERVER['REMOTE_ADDR']) {
            system_log(5,"EXTENSION_INTERNAL_NO_PERMISSION",[
                "extension_id" => extension()->_id,
            ]);
            return 'Not Allowed';
        }
        $token = Token::where('token', request('token'))->first() or abort(403, "Token gecersiz");

        auth()->loginUsingId($token->user_id);

        $server = Server::find(request('server_id')) or abort(404, 'Sunucu Bulunamadi');

        if ($server->type != "linux_ssh" && $server->type != "windows_powershell") {
            system_log(7,"EXTENSION_INTERNAL_RUN_COMMAND_FAILED",[
                "extension_id" => extension()->_id,
                "server_id" => server()->_id
            ]);
            return "Bu sunucuda komut çalıştıramazsınız.";
        }

        request()->request->add(['server' => $server]);
        $server->getFile(request('remotePath'), request('localPath'));

        system_log(7,"EXTENSION_INTERNAL_RECEIVE_FILE",[
            "extension_id" => extension()->_id,
            "server_id" => server()->_id,
            "file_name" => request('remotePath'),
        ]);

        return "ok";
    }

    /**
     * @return RedirectResponse|Redirector
     */
    public function serverSettings()
    {
        $extension_config = [];
        foreach (extension()->database as $key) {
            $extension_config[$key["variable"]] = request($key["variable"]);
        }

        $settings = auth()->user()->settings;

        $settings[server()->_id][extension()->_id] = $extension_config;
        User::where('_id', auth()->id())->update([
            "settings" => $settings
        ]);

        system_log(7,"EXTENSION_SETTINGS_UPDATE",[
            "extension_id" => extension()->_id,
            "server_id" => server()->_id,
        ]);

        return redirect(route('extension_map', [
            "extension_id" => extension()->_id
        ]));
    }

    /**
     * @return Response
     */
    public function serverSettingsPage()
    {
        $extension = Extension::where('_id', request()->route('extension_id'))->first();
        system_log(7,"EXTENSION_SETTINGS_PAGE",[
            "extension_id" => extension()->_id,
            "server_id" => server()->_id,
        ]);
        return response()->view('extension_pages.setup', [
            'extension' => $extension
        ]);
    }

    /**
     * @return JsonResponse|Response
     */
    public function remove()
    {
        try {
            self::rmdir_recursive(env('EXTENSIONS_PATH') . strtolower(extension()->name));
            foreach (Script::where('extensions', 'like', strtolower(extension()->name))->get() as $script) {
                shell_exec('rm ' . env('SCRIPTS_PATH') . $script->_id);
                $script->delete();
            }
            shell_exec('sudo userdel liman-' . extension()->_id);
            extension()->delete();
        } catch (Exception $exception) {
            return respond('Eklenti silinemedi', 201);
        }
        system_log(3,"EXTENSION_REMOVE",[
            "extension_id" => extension()->_id,
        ]);
        return respond('Eklenti Başarıyla Silindi');
    }

    /**
     * @return Factory|View
     */
    public function page()
    {
        if (request('page_name') == "functions") {
            $fileName = request('page_name') . '.php';
        } else {
            $fileName = request('page_name') . '.blade.php';
        }
        $file = file_get_contents(env('EXTENSIONS_PATH') . strtolower(extension()->name) . '/' . $fileName);
        system_log(7,"EXTENSION_CODE_EDITOR",[
            "extension_id" => extension()->_id,
            "server_id" => server()->_id,
            "file" => $fileName
        ]);
        return view('l.editor', [
            "file" => $file
        ]);
    }

    /**
     * @return JsonResponse|Response
     */
    public function updateCode()
    {
        $file = env('EXTENSIONS_PATH') . strtolower(extension()->name) . '/' . request('page') . '.blade.php';
        file_put_contents($file, json_decode(request('code')));
        system_log(7,"EXTENSION_CODE_UPDATE",[
            "extension_id" => extension()->_id,
            "server_id" => server()->_id,
            "file" => request('page')
        ]);
        return respond("Kaydedildi", 200);
    }

    private function rmdir_recursive($dir)
    {
        foreach (scandir($dir) as $file) {
            if ('.' === $file || '..' === $file) {
                continue;
            }
            if (is_dir("$dir/$file")) {
                $this->rmdir_recursive("$dir/$file");
            } else {
                unlink("$dir/$file");
            }
        }
        rmdir($dir);
    }

    private function generateSandboxCommand($serverObj, Extension $extensionObj, $user_settings, $user_id, $outputs, $viewName, $functionName)
    {
        $functions = env('EXTENSIONS_PATH') . strtolower($extensionObj->name) . "/functions.php";

        $combinerFile = env('SANDBOX_PATH') . "index.php";

        $server = str_replace('"', '*m*', json_encode($serverObj->toArray()));

        $extension = str_replace('"', '*m*', json_encode($extensionObj->toArray()));

        if (
            !array_key_exists($serverObj->_id, $user_settings) ||
            !array_key_exists($extensionObj->_id, $user_settings[$serverObj->_id])
        ) {
            $extensionDb = "";
        } else {
            $extensionDb = str_replace('"', '*m*', json_encode($user_settings[$serverObj->_id][$extensionObj->_id]));
        }

        $outputsJson = str_replace('"', '*m*', json_encode($outputs));

        $request = request()->all();
        unset($request["permissions"]);
        unset($request["extension"]);
        unset($request["server"]);
        unset($request["script"]);
        unset($request["server_id"]);
        $request = str_replace('"', '*m*', json_encode($request));

        $apiRoute = route('extension_function_api', [
            "extension_id" => $extensionObj->_id,
            "function_name" => ""
        ]);

        $navigationRoute = route('extension_server_route', [
            "server_id" => $serverObj->_id,
            "extension_id" => $extensionObj->_id,
            "city" => $serverObj->city,
            "unique_code" => ""
        ]);

        $token = Token::create($user_id);

        $command = "sudo runuser liman-" . $extensionObj->_id .
            " -c '/usr/bin/php -d display_errors=on $combinerFile $functions "
            . strtolower($extensionObj->name) .
            " $viewName \"$server\" \"$extension\" \"$extensionDb\" \"$outputsJson\" \"$request\" \"$functionName\" \"$apiRoute\" \"$navigationRoute\" \"$token\"'";

        return $command;
    }
}