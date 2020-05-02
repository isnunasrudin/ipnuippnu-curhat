<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use OneSignal;

class ApiController extends Controller
{

    public function __construct()
    {
        $this->middleware('apiauth')->except('login');
    }

    public function login(Request $request)
    {
        if( auth()->once($request->only(['email', 'password'])) )
        {
            return response()->json([
                'status' => 'success',
                'token' => \App\User::whereEmail($request->email)->get()->first()->remember_token
            ], 200);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Email / kata sandi salah'
        ], 200);
    }

    public function newpesan(Request $request)
    {
        $validator = \Validator::make($request->only(['message', 'title']),[
            'message' => 'required|max:50',
            'title' => 'required'
        ]);
        if( $validator->fails() )
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 200);

        $buat = new \App\Message;
        $buat->title = ucwords(strip_tags($request->title));
        $buat->message = $request->message;
        $buat->user_id = $request->account->id;
        $buat->save();
        
        OneSignal::sendNotificationUsingTags(
            "{$buat->user->panggilan()} mengirim curhatan baru.",
            array(["field" => "tag", "key" => "isAdmin", "relation" => "=", "value" => config('app.key')]),
            $url = route('admin.beranda'),
            $data = null,
            $buttons = null,
            $schedule = null,
            $headings = "Curhatan Baru, Masuk!"
        );

        return response()->json([
            'status' => 'success',
            'data' => $buat
        ], 200);
    }

    public function balas(Request $request)
    {
        $validator = \Validator::make($request->all(),[
            'balasan' => 'required',
            'id_pesan' => 'required|exists:messages,id'
        ], [
            'id_pesan.exists' => 'Pesan yang ditunjuk tidak ditemukan'
        ]);
        if( $validator->fails() )
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 200);

        $balasan = new \App\MessageAnswer;
        $balasan->user_id = $request->account->id;
        $balasan->user_read = 1;
        $balasan->admin_read = 0;
        $balasan->message = $request->balasan;
        $balasan->message_id = $request->id_pesan;
        $balasan->save();

        $tujuan = \App\Message::find($request->id_pesan);

        OneSignal::async()->sendNotificationUsingTags(
            $request->account->panggilan() . " telah membalas curhatan.",
            array(["field" => "tag", "key" => "isAdmin", "relation" => "=", "value" => config('app.key')]),
            $url = route('admin.pesan', $request->id_pesan),
            $data = null,
            $buttons = null,
            $schedule = null,
            $headings = "Balasan Curhatan \"$tujuan->title\"!"
        );

        $aciap = new \stdClass;
        $aciap->id = $balasan->message_id;
        $aciap->name = $balasan->user->name;
        $aciap->avatar = $balasan->user->avatar ?: 'default.jpg';
        $aciap->message = $balasan->message;
        $aciap->created_at = $balasan->updated_at->format('d M h:i a');
        $aciap->answer_id = $balasan->id;
        $aciap->is_admin = $balasan->user->is_admin;

        event(new \App\Events\TerimaBalasan($aciap));

        return response()->json([
            'status' => 'success',
            'data' => $aciap
        ], 200);
    }

    public function get_pesan(Request $request)
    {
        return \App\Message::where(['user_id' => $request->account->id])->with('message_answer.user')->get();
    }
}