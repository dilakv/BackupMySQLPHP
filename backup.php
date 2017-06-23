<?php
/*
    Este script es bastante lento. En una base de 40 mb se puede tardar hasta 1 minuto (según su servidor)
    Recomiendo utilizarlo cuando ya no hay ninguna otra opción disponible, como en un hosting compartido.
    El tiempo máximo de ejecución se establece en 1200 segundos por default, es necesario que se ajuste según las necesidades.

    El código es una adecuación a mis necesidades, el original está aquí -> https://tournasdimitrios1.wordpress.com/2012/12/09/a-php-script-to-backup-mysql-databases-for-shared-hosted-websites-3/
 */

ini_set('max_execution_time', 1200);

require('phpclass/class.phpmailer.php');
require('phpclass/class.smtp.php');
/*
 Se establecen las configuraciones
 */
// Se define el directorio en el cual se realizarán los backups
define('BACKUP_DIR', './myBackups' ) ;

// Las credenciales de acceso
define('HOST', 'localhost' ) ;
define('USER', 'Usuario' ) ;
define('PASSWORD', 'Contrasena' ) ;

// Se listan las bases de datos en un array, si es solo una se queda "$bases = ['labase'];"
$bases = ['BDdilakv_01', 'BDdilakv_02', 'BDdilakv_03'];

// En esta variable se guardan los nombres de los archivos generados
$archivos = array();


$eliminararchivos = true;

/*
Se define el nombre de archivo de búsqueda
En el archivo original sugieren que si se utiliza Amazon's S3 service, se utilicen solamente minúsculas
Cualquier cosa que siga al caracter "&" debe quedarse como está, esto establece el timestamp, que necesita el script
*/

$archiveName = $bases[0].'_mysqlbackup--' . date('d-m-Y') . '@'.date('h.i.s').'&'.microtime(true) . '.sql' ;
// Se establece el tiempo máximo de ejecución
if(function_exists('max_execution_time')) {
    if( ini_get('max_execution_time') > 0 )  set_time_limit(0) ;
}

// Fin de las configuraciones

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */
/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */
/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

/*
 Se crea el directorio de backups, sino existe con los permisos apropiados
 Se crea un .htaccess para restringir el acceso
*/
if (!file_exists(BACKUP_DIR)) mkdir(BACKUP_DIR , 0700);
if (!is_writable(BACKUP_DIR)) chmod(BACKUP_DIR , 0700);

// El archivo .htaccess limitará el acceso al directorio... por seguridad
$content = 'deny from all' ;
$file = new SplFileObject(BACKUP_DIR . '/.htaccess', "w") ;
$written = $file->fwrite($content) ;

// Verifica que el archivo haya sido creado sino, se muere
if($written <13) die("No se pudo crear el archivo \".htaccess\" , backup cancelado")  ;

// Verifica la etiqueta de tiempo del último archivo, si es mayor a 24 horas, se crea un nuevo archivo
$lastArchive = getNameOfLastArchieve(BACKUP_DIR)  ;
$timestampOfLatestArchive =  substr(ltrim((stristr($lastArchive , '&')) , '&') , 0 , -8)  ;
if (allowCreateNewArchive($timestampOfLatestArchive)) {
    for($i=0; $i < sizeof($bases); $i++) {
        $nuevoArchivo = $bases[$i].'_mysqlbackup--' . date('d-m-Y') . '@'.date('h.i.s').'&'.microtime(true) . '.sql' ;
        createNewArchive($nuevoArchivo, $bases[$i]);
    }
    mandarporcorreo();
} else {
    echo 'Todavía no... más al ratito'  ;
}
/*
###########################
 Definición de las funciones
 1) createNewArchive : Crea un nuevo archivo de respaldo
 2) getFileSizeUnit  : Obtiene el valor entero y lo retorna en la unidad que corresponde (Bytes, KB, MB)
 3) getNameOfLastArchieve : Obtiene el nombre del último archivo
 4) allowCreateNewArchive : Compara 2 timestamps y regresa "TRUE" si el último archivo se creo hace más de 24 horas
 5) eliminararchivos: Una vez que terminó de crear y de enviar los archivos por correo se eliminan.
 6) mandarporcorreo:  Manda los respaldos creados por correo
###########################
*/
function createNewArchive($archiveName, $DBName){
    global $archivos;
    $mysqli = new mysqli(HOST , USER , PASSWORD , $DBName) ;
    if (mysqli_connect_errno())
    {
        printf("No nos pudimos conectar: %s", mysqli_connect_error());
        exit();
    }
    // Información de introducción

    $return = "--\n";
    $return .= "-- Sistema de backup \n";
    $return .= "--\n";
    $return .= '-- Exportado el: ' . date("Y/m/d") . ' a las ' . date("h:i") . "\n\n\n";
    $return .= "--\n";
    $return .= "-- BD : " . $DBName . "\n";
    $return .= "--\n";
    $return .= "-- --------------------------------------------------\n";
    $return .= "-- ---------------------------------------------------\n";
    $return .= 'SET AUTOCOMMIT = 0 ;' ."\n" ;
    $return .= 'SET FOREIGN_KEY_CHECKS=0 ;' ."\n" ;
    $tables = array() ;
// Exploring what tables this database has
    $result = $mysqli->query('SHOW TABLES' ) ;
// Cycle through "$result" and put content into an array
    while ($row = $result->fetch_row())
    {
        $tables[] = $row[0] ;
    }
// Cycle through each  table
    foreach($tables as $table)
    {
// Get content of each table
        $result = $mysqli->query('SELECT * FROM '. $table) ;
// Get number of fields (columns) of each table
        $num_fields = $mysqli->field_count  ;
// Add table information
        $return .= "--\n" ;
        $return .= '-- Estructura de tabla: `' . $table . '`' . "\n" ;
        $return .= "--\n" ;
        $return.= 'DROP TABLE  IF EXISTS `'.$table.'`;' . "\n" ;
// Get the table-shema
        $shema = $mysqli->query('SHOW CREATE TABLE '.$table) ;
// Extract table shema
        $tableshema = $shema->fetch_row() ;
// Append table-shema into code
        $return.= $tableshema[1].";" . "\n\n" ;
// Cycle through each table-row
        while($rowdata = $result->fetch_row())
        {
// Prepare code that will insert data into table
            $return .= 'INSERT INTO `'.$table .'`  VALUES ( '  ;
// Extract data of each row
            for($i=0; $i<$num_fields; $i++)
            {
                $return .= '"'.$rowdata[$i] . "\"," ;
            }
            // Let's remove the last comma
            $return = substr("$return", 0, -1) ;
            $return .= ");" ."\n" ;
        }
        $return .= "\n\n" ;
    }
// Close the connection
    $mysqli->close() ;
    $return .= 'SET FOREIGN_KEY_CHECKS = 1 ; '  . "\n" ;
    $return .= 'COMMIT ; '  . "\n" ;
    $return .= 'SET AUTOCOMMIT = 1 ; ' . "\n"  ;


//$file = file_put_contents($archiveName , $return) ;
    $zip = new ZipArchive() ;
    $resOpen = $zip->open(BACKUP_DIR . '/' .$archiveName.".zip" , ZIPARCHIVE::CREATE) ;
    if( $resOpen ){
        $zip->addFromString( $archiveName , "$return" ) ;
    }
    $zip->close() ;
    $fileSize = getFileSizeUnit(filesize(BACKUP_DIR . "/". $archiveName . '.zip')) ;
    $message = <<<msg
  <h2>BACKUP  listo ,</h2><br>
  El archivo se ha nombrado como  : <b>  $archiveName  </b> y pesa :   $fileSize  .
 
 El archivo no puede ser accedido así como así, está protegido. Es altamente recomendable que se pase a algún otro lugar, no le jueges al listo. A menos que hayas marcado la opción de eliminar.
msg;

    array_push($archivos, $archiveName.'.zip');

    echo $message ;
}

function getFileSizeUnit($file_size){
    switch (true) {
        case ($file_size/1024 < 1) :
            return intval($file_size ) ." Bytes" ;
            break;
        case ($file_size/1024 >= 1 && $file_size/(1024*1024) < 1)  :
            return round(($file_size/1024) , 2) ." KB" ;
            break;
        default:
            return round($file_size/(1024*1024) , 2) ." MB" ;
    }
}

function getNameOfLastArchieve($backupDir) {
    $allArchieves = array()  ;
    $iterator = new DirectoryIterator($backupDir) ;
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isDir() && $fileInfo->getExtension() === 'txt') {
            $allArchieves[] = $fileInfo->getFilename() ;
        }
    }
    return  end($allArchieves) ;
}

// Si se desea respaldar cada 12 horas se definiría $timestamp = 12, o se mandaría el valor cuando se llame la función.
function allowCreateNewArchive($timestampOfLatestArchive , $timestamp = 24) {
    $yesterday =  mktime() - $timestamp*3600 ;
    return ($yesterday >= $timestampOfLatestArchive) ? true : false ;
}

function eliminararchivos($backupDir)
{
    global $archivos;

    for($i = 0; $i < sizeof($archivos); $i++)
    {
        unlink(BACKUP_DIR."/".$archivos[$i]);
    }
}

function mandarporcorreo()
{
    global $archivos;
    global $eliminararchivos;

    $body = "Respaldos"; // Colocar el cuerpo del correo
    $nombre = "FValencia"; // Nombre del que envía
    $asunto = "Respaldos"; // Asunto del correo

    $mail = new PHPMailer();
    $mail->SMTPDebug = 1;
    $mail->IsSMTP();

    // la dirección del servidor, p. ej.: smtp.servidor.com
    $mail->Host = "mail.miservidor.com";

    // dirección remitente, p. ej.: no-responder@miempresa.com
    $mail->From = "direcciondecorreo@miservidor.com";

    // nombre remitente, p. ej.: "Servicio de envío automático"
    $mail->FromName = $nombre;

    // asunto y cuerpo alternativo del mensaje
    $mail->Subject = $nombre." || ".$asunto;
    $mail->AltBody = "Esto es opcional";

    // Este si es importante
    $mail->Body = $body;

    // Adjunta los archivos creados
    for($i=0; $i < sizeof($archivos); $i++)
    {
        $mail->addAttachment(BACKUP_DIR."/".$archivos[$i]);
    }
    // Agregamos los destinos
    $mail->AddAddress("respaldos.ssyt@gmail.com", "FValenciaS");

    // si el SMTP necesita autenticación
    $mail->SMTPAuth = true;

    //El puerto SMTP
    $mail->Port = 587;

    // credenciales de usuario
    $mail->Username = "direcciondecorreo@miservidor.com";
    $mail->Password = "&&ContraseñaSuperSegura&&";


    if(!$mail->Send()) {
        // Si algo falla lo imprime en pantalla
       echo "Error enviando: " . $mail->ErrorInfo;
    }else{
        /* Si todo_sale bien elimina los archivos y crea uno vacío para verificar el tiempo de 24 horas */
        echo "Correo enviado";
        $eliminararchivos ? eliminararchivos(BACKUP_DIR) : false;
        touch(BACKUP_DIR.'/_mysqlbackup--' . date('d-m-Y') . '@'.date('h.i.s').'&'.microtime(true).'.txt');
    }

}