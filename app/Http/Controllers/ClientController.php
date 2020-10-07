<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Mail\SendMail;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Requests\UpdatePasswordRequest;
use App\Mail\WelcomeMail;
use Auth;

class ClientController extends Controller {

    public function register(Request $request){

        $json = $request->input('json', null);

        $parameters_object = json_decode($json);
        $parameters_array = json_decode($json, true);

        if(!empty($parameters_object) && !empty($parameters_array)){
            
            $parameters_array = array_map('trim', $parameters_array);

            $validate = Validator::make($parameters_array, [
                'name' => 'required|alpha', 
                'email' => 'required|email|unique:clients',
                'password' => 'required|min:6'  
            ]);

            if($validate -> fails() ){
          
                $data = array( 
                  'status'  => 'error',
                  'code'    =>  422,
                  'message' => 'Error al validar los datos.',
                  'error'   => $validate->errors()->first()
                );
    
            } else {
                
                $client           = new Client();
                $client->name     = $parameters_array['name'];  
                $client->email    = $parameters_array['email'];
                $client->password = bcrypt($parameters_array['password']);          
                $client->save(); 

                Mail::to($client->email )->send(new WelcomeMail($client));

                $data = array( 
                    'status'  => 'success',
                    'code'    =>  201,
                    'data'    => $client,
                    'message' => 'Cliente registrado.',
                    'client'  => $client,
                  ); 

            }

        }else{
            $data = array( 
              'status'  => 'error',
              'code'    =>  400,
              'message' => 'Error, los datos enviados no son correctos.'
            );  
                          
          }

          return response()->json($data, $data['code']);
    }
    
    /**
     * Login Req
     */
    public function login(Request $request){

        $json = $request->input('json', null);

        $params_object = json_decode($json);
        $params_array = json_decode($json, true);

       
        if(!empty($parameters_object) && !empty($parameters_array)){

            return response( array( 
                "status" => "error", 
                'code' => 400,
                "message" => "Credenciales incorrectos." ) );
        }

        $validate = Validator::make($params_array, [
            'email' => 'required|email',
            'password' => 'required|min:4',
        ]);

        if ($validate->fails()) {
            
            $data = array(
                'status' => 'error',
                'code' => 404,
                'message' => 'El cliente no se ha podido identificar',
                'errors' => $validate->errors()
            );

            return response()->json($data, $data['code']);

        }

        if(Client::where('email', $params_object->email)->count() <= 0 ) {

            $data = array(
                'status' => 'error',
                'code' => 404,
                "message" => "Cliente no existe",
                'errors' => $validate->errors()
            );
            
            return response()->json($data, $data['code']);
        }
        
    


        $client = Client::where('email', $params_object->email)->first();

        $user = Client::findOrFail($client->id);
    
        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();

            return response( array( 
                "status" => "error", 
                'code' => 400,
                "message" => "Email no verificado, revisar corrreo" ) );
        }


        if(password_verify($params_object->password, $client->password)){
           
            return response( 
                
                array( 
                    "status" => "success", 
                    'code' => 200,
                    "message" => "Acceso exitoso",
                    "client" => $client,
                    "token" => $client->createToken('Token personal de acceso',['client'])->accessToken
            
                ));

        } else {
            return response( array( 
                "status" => "error", 
                'code' => 400,
                "message" => "Credenciales incorrectos." ) );
        }
    }

    public function logout(Request $request){
        
        if (Auth::user()) {
            $user = Auth::user()->token();
            $user->revoke();

        return response()->json([
            "status" => "success", 
            'code' => 200,
            'message' => 'Cierre de sesion exitoso'
        ]);
        }else {
            return response()->json([
                "status" => "error", 
                'code' => 400,
                'message' => 'ocurrio un error al cerrar sesion'
            ]);
        }
    }

    public function sendPasswordResetEmail(Request $request){

        if(!$this->validEmail($request->email)) {
            return response()->json([
                "status" => "error", 
                'code' => 400,
                'message' => 'El correo electrónico no existe.'
            ], Response::HTTP_NOT_FOUND);
        } else {
          
            $this->sendMail($request->email);
            return response()->json([
                "status" => "success", 
                'code' => 200,
                'message' => 'Revise su bandeja de entrada, hemos enviado un enlace para restablecer el correo electrónico.'
            ], Response::HTTP_OK);            
        }
    }

    public function sendMail($email){
        $token = $this->generateToken($email);
        Mail::to($email)->send(new SendMail($token));
    }

    public function validEmail($email) {
       return !!Client::where('email', $email)->first();
    }

    public function generateToken($email){
      $isOtherToken = DB::table('password_resets')->where('email', $email)->first();

      if($isOtherToken) {
        return $isOtherToken->token;
      }

      $token = Str::random(80);;
      $this->storeToken($token, $email);
      return $token;
    }

    public function storeToken($token, $email){
        DB::table('password_resets')->insert([
            'email' => $email,
            'token' => $token,
            'created_at' => Carbon::now()            
        ]);
    }

    public function passwordResetProcess(UpdatePasswordRequest $request){
        
        return $this->updatePasswordRow($request)->count() > 0 ? $this->resetPassword($request) : $this->tokenNotFoundError();
      }
  
      // Verify if token is valid
      private function updatePasswordRow($request){
         return DB::table('password_resets')->where([
             'email' => $request->email,
             'token' => $request->passwordToken
         ]);
      }
  
      // Token not found response
      private function tokenNotFoundError() {
          return response()->json([
            "status" => "error", 
            'code' => 400,
            'message' => 'Su correo electrónico o token es incorrecto.'
          ],Response::HTTP_UNPROCESSABLE_ENTITY);
      }
  
      // Reset password
      private function resetPassword($request) {
          // find email
          $userData = Client::whereEmail($request->email)->first();
          // update password
          $userData->update([
            'password'=>bcrypt($request->password)
          ]);
          // remove verification data from db
          $this->updatePasswordRow($request)->delete();
  
          // reset password response
          return response()->json([
            "status" => "success", 
            'code' => 200,
            'message'=>'Se actualizó la contraseña.'
          ],Response::HTTP_CREATED);
      }

      public function verify($client_id, Request $request) {
        if (!$request->hasValidSignature()) {
            return response()->json(["message" => "Se ha proporcionado una URL no válida o caducada."], 401);
        }
    
        $user = Client::findOrFail($client_id);
    
        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
       
        return response()->json([
            "status" => "success", 
            'code' => 200,
            'message' => 'Cuenta verificada exitosamente'
        ]);
    }
    
    public function resend() {
        if (auth()->user()->hasVerifiedEmail()) {
            return response()->json(["message" => "Correo electrónico ya verificado."], 400);
        }
    
        auth()->user()->sendEmailVerificationNotification();
    
        return response()->json(["message" => "Enlace de verificación de correo electrónico enviado en su identificación de correo electrónico"]);
    }


    public function clientInfo() {
 
     $client = auth()->user();
         
     return response(       
        array( 
            "status" => "success", 
            'code' => 200,
            "message" => "Informacion de cliente",
            "client" => $client,
        ));
 
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Client  $client
     * @return \Illuminate\Http\Response
     */
    public function show(Client $client)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Client  $client
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Client $client)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Client  $client
     * @return \Illuminate\Http\Response
     */
    public function destroy(Client $client)
    {
        //
    }
}