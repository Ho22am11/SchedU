<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\File;

class ScriptController extends Controller
{

    public function run(Request $request)
    {
        $data = $request->validate([
            'study_plan_id' => 'required|integer',
            'name_en'       => 'required|string',
            'name_ar'       => 'required|string',
        ]);

        $python = 'C:\laragon\bin\python\python-3.10\python.exe';

        $script = base_path('script/main.py');

        if (!File::exists($python)) {
            return response()->json([
                'message' => 'Python executable not found at:',
                'path'    => $python,
            ], 500);
        }
        if (!File::exists($script)) {
            return response()->json([
                'message' => 'Script main.py not found at:',
                'path'    => $script,
            ], 500);
        }

        $process = new Process([
            $python,
            $script,
            '--study-plans', $data['study_plan_id'],
            '--name-en',      $data['name_en'],
            '--name-ar',      $data['name_ar'],
        ]);

        $process->setEnv([
            'PYTHONHASHSEED'   => '0',
            'PYTHONIOENCODING' => 'utf-8',
            'BACKEND_URL'      => 'http://127.0.0.1:8000/api',
            'EMAIL'            => 'hossam@gmail.com',
            'PASSWORD'         => '123456',
        ]);

        $process->setWorkingDirectory(base_path());

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $code   = $process->getExitCode();
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            $log    = file_exists(base_path('scheduler_debug_full.log'))
                      ? file_get_contents(base_path('scheduler_debug_full.log'))
                      : '(no log file)';

            // حالة “لا يوجد جدول صالح”
            if ($code === 1 && str_contains($stdout, 'Could not find a valid schedule')) {
                return response()->json([
                    'message' => 'No valid schedule could be generated for the given plan.',
                    'output'  => $stdout,
                ], 200);
            }

            return response()->json([
                'message'   => 'Schedule generation failed',
                'exit_code' => $code,
                'stdout'    => $stdout    ?: '(no stdout)',
                'stderr'    => $stderr    ?: '(no stderr)',
                'log'       => $log,
            ], 500);
        }

        return response()->json([
            'message' => 'Schedule generated successfully',
            'output'  => $process->getOutput(),
        ], 200);
    }

}
