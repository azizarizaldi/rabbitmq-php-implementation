<?php
date_default_timezone_set("Asia/Bangkok");

require_once __DIR__ . '/../vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->load();

class Producer {
    private $connection;
    private $channel;
    private $callback_queue;
    private $response;
    private $corr_id;

    public function __construct(){
       $this->connection = new AMQPStreamConnection(
           $_ENV['RABBITMQ_HOST'],
           $_ENV['RABBITMQ_PORT'], // default : 5672
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
       $this->callback_queue = null;
       list($this->callback_queue, ,) = $this->channel->queue_declare(
            "",
            false,
            false,
            true,
            false
        );

        // consumer
        $this->channel->basic_consume(
            $this->callback_queue,
            '',
            false,
            true,
            false,
            false,
            array(
                $this,
                "on_response"
            )
        );
    }
  
    // get response callback
    public function on_response($rep){
        if ($rep->get('correlation_id') == $this->corr_id) {
            $this->response = $rep->body;
        }
    }

    public function index() {
        $fullname       = isset($_POST['name']) ? $_POST['name'] : '-';
        $sending_date   = date("Y-m-d H:i:s");
        $queue_name     = 'test_pdf_download';

    	$this->response = null;
        $this->corr_id = uniqid();
		
        $payload = json_encode(
            array(
                "id"            => rand(),
                "fullname"      => $fullname,
                "sending_date"  => $sending_date
            )
        );

		$msg = new AMQPMessage(
		    $payload,
		    array(
			    'correlation_id' => $this->corr_id,
			    'reply_to' => $this->callback_queue
			)
		);

		// publish message to rabbitmq
		$this->channel->basic_publish($msg, '', $queue_name);

		// wait for response
		while (!$this->response) {
			try {
		        $this->channel->wait();
		    } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
		        echo $e->getMessage();
		        break;
		    } catch (\PhpAmqpLib\Exception\AMQPRuntimeException $e) {
		        echo $e->getMessage();
		        break;
		    }
		}


        return json_decode($this->response, 1);
    }
}

$producer   = new Producer();
$response   = $producer->index();

header('Content-type: application/json');
echo json_encode( $response )
?>