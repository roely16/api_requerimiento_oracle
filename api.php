<?php
        
    error_reporting(E_ERROR | E_PARSE);

    header('Access-Control-Allow-Origin: *');
    header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
    header("Allow: GET, POST, OPTIONS, PUT, DELETE");

    require_once __DIR__ . '/vendor/autoload.php';

    include $_SERVER['DOCUMENT_ROOT'] . '/apis/api_requerimiento/sap/functions.php';

    class Api extends Rest{

        public $dbConn;

		public function __construct(){

			parent::__construct();

			$db = new Db();
			$this->dbConn = $db->connect();

        }

        public function generar_requerimiento(){
            
            $interlocutor = $this->validateParameter('interlocutor', $this->param['interlocutor'], STRING);

            $fecha = $this->validateParameter('fecha', $this->param['fecha'], STRING);

            $query = "  SELECT 
                            interlocutor, nota, substr(nombre, 1, 67) AS nombre_part1, 
                            substr(nombre, 68, 134) AS nombre_part2,  
                            substr(direccion, 1, 67) AS direccion_part1,
                            substr(direccion, 68, 134) AS direccion_part2, 
                            nit, 
                            to_char(total, 'FM999,999,990.00') AS total_pagar,
                            '15 DE MARZO DE 2020' AS fecha_corte, 
                            '30 DE ABRIL DE 2020' AS fecha_vence,
                            '15.03.2020' AS fecha_referencia, 
                            '30.03.2020' AS vencimiento,
                            '1er. Trimestre de 2020' AS periodo_fiscal
                        FROM canal_1002
                        WHERE interlocutor = '$interlocutor'";

            $stid = oci_parse($this->dbConn, $query);
            oci_execute($stid);

            $encabezado = oci_fetch_array($stid, OCI_ASSOC);

            if (empty($encabezado)) {
                
                $this->throwError(100, "No se han encontrado datos para el interlocutor ingresado");

            }

            //Solicitar los datos de SAP
            $url = "http://172.23.25.36/apis/api_requerimiento/";

            $ch = curl_init($url);

            $data = [

                "name" => "generar_requerimiento",
                "param" => [
                    "interlocutor" => "0" . $interlocutor,
                    "fecha" => $fecha
                ]

            ];

            $payload = json_encode( $data );

            curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

            $result = json_decode(curl_exec($ch), true);
            curl_close($ch);

            $result = $result["response"]["result"];

            //Total del requerimiento de pago
            $encabezado["TOTAL_SAP"] = number_format($result["BETRZ"], 2);

            // Obtener los interlocutores diferentes

            $interlocutores = [];
            $last = "";

            foreach ($result["T_OI"] as $item) {

                if ($last != $item["GPART"]) {
                    
                    $interlocutor_predio = $item["GPART"];

                    //Buscar en canal_2
                    $query = "  SELECT 
                                    interlocutor_predio, 
                                    SUBSTR(direccion_predio,1,26) AS direccionPredio1, 
                                    SUBSTR(direccion_predio,27,26) AS direccionPredio2,
                                    matricula, 
                                    SUBSTR(registro,1,19) AS registro, 
                                    to_char(valor_predio,'FM999,999,990.00') AS valorPredio,  
                                    to_char(tasa,'FM999,999,990.00') AS tasa, 
                                    to_char(impuesto,'FM999,999,990.00') AS cargos, 
                                    to_char(saldo,'FM999,999,990.00') AS saldo, 
                                    to_char(multa,'FM999,999,990.00') AS multas,
                                    to_char(saldo_convenio,'FM999,999,990.00') AS saldoConvenio, 
                                    to_char(total_predio,'FM999,999,990.00') AS totalInmueble
                                FROM canal_2
                                WHERE interlocutor_predio = '$interlocutor_predio'";

                    $stid = oci_parse($this->dbConn, $query);
                    oci_execute($stid);

                    $detalle_interlocutor = oci_fetch_array($stid, OCI_ASSOC);

                    $interlocutores [] = $detalle_interlocutor;

                }

                $last = $item["GPART"];

            }

            // por cada uno de los predios

            foreach ($interlocutores as &$interlocutor) {
                
                $inter = "0" . $interlocutor["INTERLOCUTOR_PREDIO"];
                $datos_calculo = [];
                $saldo = 0;
                $multas = 0;
                $convenios = 0;

                foreach ($result["T_OI"] as $item) {
                                        
                    if ($inter ==  $item["GPART"] && $item["ZZCHECK"] == "X") {
                        
                        $datos_calculo [] = $item;

                        //IUSI
                        if ($item["HVORG"] == "4010" && $item["TVORG"] == "0110") {
                            
                            $saldo = $saldo + $item["BETRH"];

                        }

                        //Convenios
                        if ($item["HVORG"] == "0080" && $item["TVORG"] == "0010") {
                            
                            $convenios = $convenios + $item["BETRH"];

                        }

                        //Multas
                        if ($item["HVORG"] == "0040" && $item["TVORG"] == "0015") {
                            
                            $multas = $multas + $item["BETRH"];

                        }

                    }
                }

                $interlocutor["SALDO_SAP"] = number_format($saldo, 2);
                $interlocutor["CONVENIOS_SAP"] = number_format($convenios, 2);
                $interlocutor["MULTAS_SAP"] = number_format($multas, 2);
                $interlocutor["TOTALINMUEBLE_SAP"] = number_format($saldo + $convenios + $multas, 2);
                
                $interlocutor["datos"] = $datos_calculo;

            }

            $data = [
                "encabezado" => $encabezado,
                "inter" => $interlocutores,
                "result" => $result
            ];

            //$this->returnResponse(SUCCESS_RESPONSE, $data);

            /*
            $query = "  SELECT 
                            interlocutor_predio, 
                            SUBSTR(direccion_predio,1,26) AS direccionPredio1, 
                            SUBSTR(direccion_predio,27,26) AS direccionPredio2,
                            matricula, 
                            SUBSTR(registro,1,19) AS registro, 
                            to_char(valor_predio,'FM999,999,990.00') AS valorPredio,  
                            to_char(tasa,'FM999,999,990.00') AS tasa, 
                            to_char(impuesto,'FM999,999,990.00') AS cargos, 
                            to_char(saldo,'FM999,999,990.00') AS saldo, 
                            to_char(multa,'FM999,999,990.00') AS multas,
                            to_char(saldo_convenio,'FM999,999,990.00') AS saldoConvenio, 
                            to_char(total_predio,'FM999,999,990.00') AS totalInmueble
                        FROM canal_2
                        WHERE interlocutor = '$interlocutor'";

            $stid = oci_parse($this->dbConn, $query);
            oci_execute($stid);

            $predios = [];

            while($data = oci_fetch_array($stid, OCI_ASSOC)){
                
                $predios [] = $data;

            }

            $result["predios"] = $predios;

            //$this->returnResponse(SUCCESS_RESPONSE, $result);
            */

            $mpdf = new \Mpdf\Mpdf(['orientation' => 'L', 'setAutoTopMargin' => 'stretch']);
    
            $obj_result = (object) $encabezado;

            //$mpdf->WriteHTML($content);

            $html = '

                <style>
                    p {
                        margin: 0;
                        padding: 0;
                    }

                    body {
                        font-family: "Courier Roman", Courier, monospace;
                        font-size: 12px
                    }

                    table {
                        border-collapse: collapse;
                    }

                    table, th, td {
                        border: 1px solid black;
                    
                    }

                </style>

                <htmlpageheader name="MyHeader1">
                    <div>
                        <div style="display: table; clear: both;">
                            <div style="float: left; width: 15%">
                                <img width="150" src="logo.png" alt="">
                            </div>
                        <div>
                            <div style="text-align: center;">
                                <span style="font-size: 16px; text-align: center;"><strong>REQUERIMIENTO DE PAGO NO. ' .$obj_result->NOTA . '</strong></span>
                                <p style="font-size: 16px; margin-bottom: 5px">Fecha Referencia: ' . $obj_result->FECHA_REFERENCIA . '</p>
                            </div>
                            <p>NUMERO DE CUENTA: '.$obj_result->INTERLOCUTOR.'</p>
                            <p style="margin-bottom: 5px">NOMBRE DEL CONTRIBUYENTE: '.$obj_result->NOMBRE_PART1.' '.$obj_result->NOMBRE_PART2.'</p>
                            <p>DOMICILIO: '.$obj_result->DIRECCION_PART1.' '.$obj_result->DIRECCION_PART2.'</p>
                        </div>
                    </div>
                </htmlpageheader>

                <htmlpagefooter name="MyFooter1">
                    <hr>
                    <p><strong>NOTA: EL TOTAL A CANCELAR PUEDE VARIAR DESPÚES DE LA FECHA DE REFERENCIA, POR ACTUALIZACIÓN OPERADA EN LA BASE DE DATOS.</strong></p>
                    <div style="display: table; clear: both; margin-top: 5px">
                        <div style="float: left; width: 33%">
                            <p>Fecha de vencimiento: '.$obj_result->VENCIMIENTO.'</p>
                        </div>
                        <div style="float: left; width: 33%; text-align: center">
                            <p>Operador Responsable: <strong>MAIL.MUNI</strong></p>
                        </div>
                        <div style="float: left; width: 33%; text-align: right">
                            <p>PAGINA {PAGENO} DE {nbpg}</p>
                        </div>
                    </div>
                </htmlpagefooter>

                <sethtmlpageheader name="MyHeader1" value="on" show-this-page="1" />
                <sethtmlpagefooter name="MyFooter1" value="on" show-this-page="1" />';
                


            $html.=  '
                    <br>
                    <div class="row">
                        <div class="col-12">
                            <table width="100%" style="font-size: 10px; margin-top: 65px">
                                <thead>
                                    <tr >
                                        <th height="40">INTERLOCUTOR</th>
                                        <th>MATRICULA</th>
                                        <th>DIRECCIÓN DEL INMUEBLE</th>
                                        <th>REGISTRO</th>
                                        <th>VALOR TOTAL</th>
                                        <th>TASA</th>
                                        <th>CARGO TRIMESTRAL</th>
                                        <th>SALDO</th>
                                        <th>MULTA</th>
                                        <th>CONVENIO</th>
                                        <th>TOTAL</th>
                                    </tr>
                                </thead>
                                <tbody>';

            foreach ($interlocutores as $predio) {

                $obj_predio = (object) $predio;

                $html.= ' <tr>
                <td width="8%" height="40">'.$obj_predio->INTERLOCUTOR_PREDIO.'</td>
                <td width="8%">'.$obj_predio->MATRICULA.'</td>
                <td width="15%">'.$obj_predio->DIRECCIONPREDIO1.' '.$obj_predio->DIRECCIONPREDIO2.'</td>
                <td>'.$obj_predio->REGISTRO.'</td>
                <td align="right">'.$obj_predio->VALORPREDIO.'</td>
                <td align="right">'.$obj_predio->TASA.'</td>
                <td width="12%" align="right">'.$obj_predio->CARGOS.'</td>
                <td align="right">'.$obj_predio->SALDO_SAP.'</td>
                <td align="right">'.$obj_predio->MULTAS_SAP.'</td>
                <td align="right">'.$obj_predio->CONVENIOS_SAP.'</td>
                <td align="right">'.$obj_predio->TOTALINMUEBLE_SAP.'</td>
                </tr>';


            }

                        
            $html.=  '</tbody>
                            </table>
                            <table style="margin-left: 515px">
                                <tr>
                                    <td height="40" width="322" style="font-size: 16px;"><strong>TOTAL A CANCELAR</strong></td>
                                    <td align="right" width="250" style="font-size: 16px"><strong>'.$obj_result->TOTAL_SAP.'</strong></td>
                                </tr>
                            </table>
                        </div>
                    </div>
            ';

            $mpdf->WriteHTML($html);


            $mpdf->Output();

            

        }

        public function  obtener_trimestres(){

            

        }

    }

?>