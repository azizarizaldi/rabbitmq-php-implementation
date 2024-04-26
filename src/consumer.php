<?php
date_default_timezone_set("Asia/Bangkok");

require_once __DIR__ . '/../vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Mpdf\Mpdf;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->load();

class Consumer {
    private $connection;
    private $channel;
    private $callback_queue;
    private $response;
    private $corr_id;

    public function __construct(){
        $this->connection = new AMQPStreamConnection(
            $_ENV['RABBITMQ_HOST'],
            $_ENV['RABBITMQ_PORT'],
            $_ENV['RABBITMQ_USER'],
            $_ENV['RABBITMQ_PASS'],
            $_ENV['RABBITMQ_VHOST'],
            false, // insist
            'AMQPLAIN', // login method
            null, // login response
            'en_US', // locale
            3.0, // connection timeout
            120, // read write timeout
            null, // context
            false, // keep alive
            60 // heartbeat / healthcheck
        );
 
        $this->channel = $this->connection->channel();

        // S3 CONFIGURATION
        $this->s3config = new stdClass();

        $credentials = [
            'bucket'    => $_ENV['S3_BUCKET'],
            'region'    => $_ENV['S3_REGION'],
            'key'       => $_ENV['S3_KEY'],
            'secret'    => $_ENV['S3_SECRET'],
            'endpoint'  => $_ENV['S3_ENDPOINT']
        ];
  
        $this->s3config->endpoint   = $credentials['endpoint'];;
        $this->s3config->bucket     = $credentials['bucket'];
        $this->s3config->region     = $credentials['region'];
        $this->s3config->key        = $credentials['key'];
        $this->s3config->secret     = $credentials['secret'];
        $this->s3config->version    = "2006-03-01";
     }
 

     public function generate_pdf($fullname="") {
        $client = S3Client::factory([
            'region'    => $this->s3config->region,
            'version'   => $this->s3config->version,
            'endpoint'  => $this->s3config->endpoint,
            'credentials' => [
                'key'       => $this->s3config->key,
                'secret'    => $this->s3config->secret
            ]
        ]);
        
        try {
            $mpdf = new Mpdf();
            $mpdf->WriteHTML('<h2 style="text-align:center">'.$fullname.'</h2>');
            $pdfContent = $mpdf->Output('', 'S');
            $filename   = 'consumer-pdf-'.strtotime(date("Y-m-d H:i:s"));

            // Simpan konten PDF ke file sementara
            $tempFile   = tempnam(sys_get_temp_dir(), 'mpdf_');
            file_put_contents($tempFile, $pdfContent);

            // Mendapatkan ukuran file
            $filesize       = filesize($tempFile);
            $target_path    = 'mpdf_testing_file/'.$filename.'.pdf';

            $source_path    = $tempFile;
            $content_type   = mime_content_type($tempFile);

            $result = $client->putObject([
                'Bucket'        => $this->s3config->bucket,
                'Key'           => $target_path,
                'SourceFile'    => $source_path,
                'ContentType'   => $content_type,
                'StorageClass'  => 'STANDARD',
                'ACL'           => 'public-read'
            ]);

            $urlFile    = $result['ObjectURL'];
            $result     = array('status' => true, 'url' => $urlFile, 'filesize' => $filesize);            
        } catch (S3Exception $e) {
            $result = array('status' => false, 'message' => $e->getMessage());
        }

        return $result;
    }

    public function run(){
        echo "========================== Start Service ==========================\n";
        $this->channel = $this->connection->channel();
        $this->channel->queue_declare('test_pdf_download', false, false, false, false);

        // create callback function
        $callback = function ($msg) {
            echo "[✔] Successfully received request!";

            // get payload body
            $payload = $msg->getBody();
            $data    = json_decode($payload, true);
            
            echo "\n[-][---- payload ----]";
            echo "\n[-][-----------------] id : ".$data['id'];
            echo "\n[-][-----------------] fullname : ".$data['fullname'];
            echo "\n[-][-----------------] sending_date : ".$data['sending_date'];
            echo "\n[-][---- Loading ----] is processing.....";
            
            // Generate PDF and upload to s3 file
            $generate_pdf = $this->generate_pdf($data['fullname']);
            
            sleep(3);
            
            $url_file = "";
            if($generate_pdf['status']) {
                $url_file = $generate_pdf['url'];
            }
            
            // optional for testing
            try {
                $corr_id = $msg->get('correlation_id');
            } catch (\OutOfBoundsException $e) {
                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);            
                echo "request processed! \n";
                return;
            }

            $data['received_date']  = date("Y-m-d H:i:s");
            $data['url_file']       = $url_file;

            // set response
            $response = json_encode(array(
                "code"      => 200,
                "status"    => true,
                "message"   => "Success",
                "data"      => $data
            ));

            // set response message
            $res = new AMQPMessage(
                $response,
                array('correlation_id' => $msg->get('correlation_id'))
            );

            // publish response back to sender
            $msg->delivery_info['channel']->basic_publish(
                $res,
                '',
                $msg->get('reply_to')
            );

            $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
            echo " done ✔ \n";
            echo "[✔] Process finished\n";
            echo "[✔] Response sent successfully to ".$data['fullname']." (".$data['id'].")\n";
            echo "========================== Queue Complete ==========================\n";
        };

        $this->channel->basic_qos(null, 1, null);
        $this->channel->basic_consume('test_pdf_download', '', false, false, false, false, $callback);

        // keep runtime alive
        while (count($this->channel->callbacks)) {
            try {
                echo "[x] Waiting for request...\n";
                $this->channel->wait();
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                // Do nothing.
                echo "error 1 \n";
                echo $e->getMessage();
                break;
            } catch (\PhpAmqpLib\Exception\AMQPRuntimeException $e) {
                // Do nothing.
                echo "error 2 \n";
                echo $e->getMessage();
                break;
            }
        }

        // close connection
        $this->channel->close();
        $this->connection->close();
    }
}

$consumer = new Consumer();
$consumer->run();
?>