<?php 
require_once( '../www/backend_cfg.php' );
require_once( '../www/backend_lib.php' );

/*	------------------------------------------------------------------------------	
// LamPI-daemon.php, Daemon Program for LamPI, controller of klikaanklikuit and action equipment
//	
// Author: M. Westenberg (mw12554 @ hotmail.com)
// (c). M. Westenberg, all rights reserved	
// Version 1.5, Oct 20, 2013. Implemented connections, started with websockets 
//				as an option next (!) to .ajax calls.
// Version 1.6. Nov 10, 2013 Enhanced support for receivers, added new devices
// Version 1.7, Dec 06, 2013 Redo of jQuery Mobile for version jqm version 1.4
// Version 1.8, Jan 18, 2014 Added temperature sensor support
// Version 1.9, Mar 10, 2014 Support for sensors, and remote access
//
// Copyright, Use terms, Distribution etc.
// ===================================================================================
//  This software is licensed under GNU General Pulic License as detailed in the 
//  root directory of this distribution and on http://www.gnu.org/licenses/gpl.txt
//
//  The above copyright notice and this permission notice shall be included in
//  all copies or substantial portions of the Software.
// 
//  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
//  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
//  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
//  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
//  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
//  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
//  THE SOFTWARE.
//
//    You should have received a copy of the GNU General Public License
//    along with LamPI.  If not, see <http://www.gnu.org/licenses/>.
//
// THIS Program file in PHP implements the daemon program to run in the background
//	It will read timer command from MySQL and then run them either immediately or on
//	certain moments based on timer settings.
//	
	-------------------------------------------------------------------------------	*/
				

// Global setting vars
//
$interval = 30;									// If no messages on socket and NO scheduled timing events, sleep

$apperr = "";									// Global Error. Just append something and it will be sent back
$appmsg = "";									// Application Message (from backend to Client)

$timers=  array();
$rooms=   array();
$scenes=  array();
$devices= array();
$settings=array();
$handsets=array();
$brands = array();
$weather= array();

/** --------------------------------------------------------------------------------------
	RRDTOOL
	The class for rrdtool functions. Creat a rrtool database and add data to it.
	
*/
class Rrd {
	
}


/** --------------------------------------------------------------------------------------
	Some user related functions needed for credential checking etc.
	The class is extensible
*/
class User {
	
	// Password Check function
	public function pwcheck($data)
	{
		global $u_admin;			// Declared in the backend_cfg.php file
		for ($i=0; $i< count($u_admin); $i++)
		{
			if (($data['login'] == $u_admin[$i]['login']) &&
				($data['password'] == $u_admin[$i]['password']))
			{
					return(1);
			}
		}
		return(0);
	}
	
}

/* ----------------------------------------------------------------------------------
 *
 *
 */
 
class Zwave {
}

/** ---------------------------------------------------------------------------------- 
 * Retrieve the current value of a switch. We need to see how we can periodically pull
 * values of all connected Z-Wave devices in our network
 *
 */
function zwave_scan($msg) {
	
}


/** ---------------------------------------------------------------------------------- 
 * Send the $,sg to the Razberry machine. At this moment we have the Razberry server
 * as a separeate device on the network.
 *
 */
function zwave_send($msg) {
	global $log;
	global $razberry;
	
	//				$bcst = array (							// build broadcast message
	//					// Remainder of record specifies device parameters
	//					'gaddr'  => $device['gaddr'],
	//					'uaddr'  => $dev."",				// From the sscanf command above, cast to string
	//					'brand'  => $brand,					// NOTE brand is a string, not an id here
	//					'val'    => $sndval,				// Value is "on", "off", or a number (dimvalue) 1-32
	//					'message' => $items[$i]['cmd']		// The GUI message, ICS encoded 
	//				);
	$log->lwrite("zwave_send started\n",2);
	
	// Device address is 1 less than the address in the user interface
	$addr = $msg['uaddr'] - 1;
	
	$ch = curl_init();
	if ($ch == false) {
		$log->lwrite("curl error");
		return(-1);
	}
	
	$p = '';
	switch ($msg['val']) {
		case 'on':
			$p = 32;
		break;
		case 'off':
			$p = 0;
		break;
		// This is probably a dim value. Value is between 0 and 32, so for 
		// this means for Fibaro with a value betwen 0 and 100% (actually 99%) that we need to normalize.
		default: 
			$p = $msg['val']/32*99;
		break;
	}
	$log->lwrite("zwave_send:: razberry is: ".$razberry.", uaddr: ".$addr.", val: ".$p);
	curl_setopt_array (
		$ch, array (
		
		CURLOPT_URL => 'http://'.$razberry.':8083/ZAutomation/OpenRemote/SwitchMultilevelSet/'.$addr.'/0/'.$p ,
		CURLOPT_RETURNTRANSFER => true
		));

	$output = curl_exec($ch);
	if ($output == false) {
		$log->lwrite("zwave_send:: curl_exec returned false",1);
		curl_close($ch);
		return -1;
	}
	if ($output == '"'.$p.'"') {
		$log->lwrite("zwave_send:: curl_exec set correctly",2);
	}
	else {
		$log->lwrite("zwave_send ERROR:: curl_exec returned ".$p." but set incorrect",1);	
	}
	$log->lwrite("zwave_send:: Output is: ".$output,2);	
	curl_close($ch);
}


/** --------------------------------------------------------------------------------------
 * Logging class:
 * - contains lfile, lwrite and lclose public methods
 * - lfile sets path and name of log file
 * - lwrite writes message to the log file (and implicitly opens log file)
 * - lclose closes log file
 * - first call of lwrite method will open log file implicitly
 * - message is written with the following format: [d/M/Y:H:i:s] (script name) message
 */
class Logging {

    // declare log file and file pointer as private properties
    private $log_file, $fp;
    // set log file (path and name)
	
    public function lfile($path) {
        $this->log_file = $path;
    }
	
    // write message to the log file
    public function lwrite($message,$dlevel=false) {
		global $debug;
		// If we specify a minimum debug level required to log the message
		if (($dlevel) && ($dlevel>$debug)) return(0);
        // if file pointer doesn't exist, then open log file
        if (!is_resource($this->fp)) {
            $this->lopen();
        }
        // define script name
        $script_name = pathinfo($_SERVER['PHP_SELF'], PATHINFO_FILENAME);
        // define current time and suppress E_WARNING if using the system TZ settings
        // (don't forget to set the INI setting date.timezone)
        $time = @date('[d/M/y, H:i:s]');
        // write current time, script name and message to the log file
        fwrite($this->fp, "$time ($script_name) $message" . PHP_EOL);
    }
	
    // close log file (it's always a good idea to close a file when you're done with it)
    public function lclose() {
        fclose($this->fp);
    }
	
    // open log file (private method)
    private function lopen() {
        // in case of Windows set default log file
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $log_file_default = 'c:/php/logfile.txt';
        }
        // set default log file for Linux and other systems
        else {
            $log_file_default = '/tmp/logfile.txt';
        }
        // define log file from lfile method or use previously set default
        $lfile = $this->log_file ? $this->log_file : $log_file_default;
        // open log file for writing only and place file pointer at the end of the file
        // (if the file does not exist, try to create it)
        $this->fp = fopen($lfile, 'a') or exit("Can't open $lfile!");
    }
}

/** ---------------------------------------------------------------------------------- 
 * Check if a client IP is in our Server subnet
 *
 * @param string $client_ip
 * @param string $server_ip
 * @return boolean
 *
 * Original function taken from internet, but modified to read server address from ifconfig
 * It provides a solution, even for multiple adpters, provided they are all in same subnet.
 * If we use PHP as a server, then we are NOT sure about the used IP address, 
 * especially if we rely on /etc/hosts as a guide (127.0.1.1)
 * as we might manually set the IP address in /etc/network/interfaces
 * Therefore, best is to use ifconfig output and scan for that interface that has a Bcast
 * next to the inet address.
 */
function clientInSameSubnet($client_ip=false,$server_ip=false) {
	global $log;
    if (!$client_ip)
        $client_ip = $_SERVER['REMOTE_ADDR'];
    //if (!$server_ip) {
    //    $server_ip = $_SERVER['SERVER_ADDR'];	// For a daemon, this does NOT work
	//}
    // Extract broadcast and netmask from ifconfig
    if (!($p = popen("/sbin/ifconfig","r"))) return false;
    $out = "";
    while(!feof($p))
        $out .= fread($p,1024);
    
    $match  = "/^.*inet addr:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})";
    $match .= ".*Bcast:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})";
    $match .= ".*Mask:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/im";
	
    if (!preg_match($match,$out,$regs)) {
		$log->lwrite("clientInSameSubnet preg_match failed");
        return false;
	}
	$log->lwrite("clientInSameSubnet:: Inet: ".$regs[1].", Bcast: ".$regs[2].", Mask: ".$regs[3],3);
	$server_ip = $regs[1];
    $bcast = ip2long($regs[2]);
    $smask = ip2long($regs[3]);
    $ipadr = ip2long($client_ip);
	if ($client_ip == '127.0.0.1') return(1);				// localhost is local subnet too.
    $nmask = $bcast & $smask;
    return (($ipadr & $smask) == ($nmask & $smask));
}


/* ---------------------------------------------------------------------------------------------
* CLASS	Queue definition for device actions that are in timing queue
*
* Item to insert ($item):
*	- scene: name of the scene, may be empty for single device actions
*	- cmd: Device command
*	- secs (interval between several elements in same scene). Maye be now == time() for immediate action
*
*
* Queue element is object { scene, cmd, time }
*	- scene: The scene name that this command belongs to
*	- cmd: the command itself (ICS-1000 message), but at this moment e assume only device messages !RxDxFx or !RxDxFdPyy
*	- time: Amount of seconds before the cmd has to fire
*
* NOTE: For other commands like RxFa (all OFF in room x) we translate upon reception. The queue stays clean.
*
*/
class Queue {
	
	private $q_list = [];
	// Insert based on timing. This takes extra time initially, but makes our live later easier
	// We know then that all actions in queue are sorted on time, first coming soonest
	public function q_insert($item) {
		global $log;
		global $debug;
		
		for ($i=count($this->q_list); $i>0 ; $i--) {
			if ( $this->q_list[$i-1]['secs'] < $item['secs'] ) {
				
				break;
			}
			$this->q_list[$i] = $this->q_list[$i-1] ;
		}
		$this->q_list[$i] = $item;
		if ($debug>2) $log->lwrite("q_insert:: Splicing queue at position: ".$i);
	}
	
	// Print the items in the queue
	public function q_print() {
		global $log;
		$tim = time();
		$log->lwrite("q_print:: Listing Queue, starting on: ".date('[d/M/Y:H:i:s]',$tim));
		
		for ($i=0; $i< count($this->q_list); $i++) {
			$log->lwrite("q_print:: Item: ".$i."::".$this->q_list[$i]['scene'].",".$this->q_list[$i]['cmd'].",".date('[d/M/Y:H:i:s]',$this->q_list[$i]['secs']));
		}
	}
	
	// If current time is > than the timestamp of an item in the queue
	// we should pop that item from the queue (can be multiple) and return those records.
	public function q_pop() {
		global $debug;
		global $log;
		$tim = time();
		$result = [];
		$i = 0;
		if ($debug > 2) $log->lwrite("q_pop:: looking for runnable items on queue");
		for ($i=0; $i<count($this->q_list); $i++) {
			if ($this->q_list[$i]["secs"] > $tim ) {
				break;
			}
			if ($debug>1) {
				$log->lwrite("q_pop:: pop Item ".$i.": ".$this->q_list[$i]['action']);
			}
		}
		$result = array_splice($this->q_list,0,$i);
		return($result);
	}// q_pop
	
	// Returns the number of seconds to go before the next item on queue becomes runnable
	public function q_tim() {
		global $debug;
		global $log;
		if (count($this->q_list) > 0)
			return($this->q_list[0]['secs']);
		else
			return(-1);
	}
	
}// Class QUEUE


/* -----------------------------------------------------------------------------------------------
	CLASS SOCKet
	Handles all socket communication functions.
	Until version 1.4 all sockets were UDP based. Starting version 1.5 the LamPI daemon will change
	to TCP connection based sockets. This will allow websockets (that are TCP only) migration in the
	front-end app.
	
 * Websockets work just like normal sockets. ONLY, when the client makes a connection
 * it is required that we upgrade the regular web/browser connection to a full
 * tcp connect including json support and masking of the data (security)
 *
 * Therefore functiosn mask/s_unmask are introduced. It is possible to have regular and
 * websockets work next to each other, but funcing out whether the message just received
 * is a websocket message is a little creative/tricky.
	
	The select call for socket can wait on several connection end-points. Therefore we will also
	introduce some security in later versions, so that some devices may and other may not control LamPI.
*/

class Sock {
	
	public	$usock = 0;					// UDP Receive Socket
	public	$rsock = 0;					// Receive socket of the server
	public	$ssock = 0;					// Sendto Socket; often last socket rcvd on, so THE socket to reply to
	public	$clientIP;					// Refer to with either $sock-> or $this->
	public	$clients = array();			// Array of sockets containing the real "accepted" clients
	public	$sockadmin = array();		// contains name, ip, type of client etc data
	
	private $read = array();			// The object for socket_select, contains array of data sockets
	private $wait = 1;					// Timeout value for socket_select. Changed dynamically!

	//
	//handshake new client. Also called upgrade of a websocket connection request
	// Websites:
	//
	private function s_upgrade($rcvd_header, $client_conn, $host, $port)
	{
		global $debug, $log;
		$log->lwrite("s_upgrade:: building upgrade reply",2);
		$headers = array();
		$lines = preg_split("/\r\n/", $rcvd_header);
		foreach($lines as $line)
		{
			$line = chop($line);
			if(preg_match('/\A(\S+): (.*)\z/', $line, $matches))
			{
				$headers[$matches[1]] = $matches[2];
			}
		}
		// XXX Need to figure out the name of this program through $_SYSTEM or $_SESSION
		// If index Sec-Websocket-Key not found we get a warning! So maybe we shoud print the
		// total header for debug>2 to see where this comes from
		$secKey = $headers['Sec-WebSocket-Key'];
		
		$log->lwrite("s_upgrade:: secKey: ".$secKey,2);
	
		$secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
		//hand shaking header, and write the upgrade response back to the client
		$upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
					"Upgrade: Websocket\r\n" .
					"Connection: Upgrade\r\n" .
					"WebSocket-Origin: $host\r\n" .
					"WebSocket-Location: ws://$host:$port/LamPI-daemon.php\r\n".
					"Sec-WebSocket-Accept:$secAccept\r\n\r\n";
		socket_write($client_conn,$upgrade,strlen($upgrade));
		$log->lwrite("s_upgrade:: sending upgrade reply",1);
		$log->lwrite("\n".$upgrade,3);
	}

	//
	//Encode message for transfer to client.
	//
	public function s_mask($text)
	{
		$b1 = 0x80 | (0x1 & 0x0f);
		$length = strlen($text);
	
		if($length <= 125)
			$header = pack('CC', $b1, $length);
		elseif($length > 125 && $length < 65536)
			$header = pack('CCS', $b1, 126, $length);
		elseif($length >= 65536)
			$header = pack('CCN', $b1, 127, $length);
		return $header.$text;
	}//mask

	//
	// May be improved function to encode messages prior to transmission
	//
	public function s_encode($message, $messageType='text') {
		global $log;
		global $debug;
		$log->lwrite("s_encode:: message: ".$message.", type: ".$messageType,3);
		switch ($messageType) {
			case 'continuous':
				$b1 = 0;
			break;
			case 'text':
				$b1 = 1;
			break;
			case 'binary':
				$b1 = 2;
			break;
			case 'close':
				$b1 = 8;
			break;
			case 'ping':
				$b1 = 9;
			break;
			case 'pong':
				$b1 = 10;
			break;
		}
		$b1 += 128;
		$length = strlen($message);
		$lengthField = "";
                
		if ($length < 126) {
			$b2 = $length;
		} elseif ($length <= 65536) {
			$b2 = 126;
			$hexLength = dechex($length);
			//$this->stdout("Hex Length: $hexLength");
			if (strlen($hexLength)%2 == 1) {
				$hexLength = '0' . $hexLength;
			} 

			$n = strlen($hexLength) - 2;
			for ($i = $n; $i >= 0; $i=$i-2) {
				$lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
			}

			while (strlen($lengthField) < 2) {
				$lengthField = chr(0) . $lengthField;
			}
		} else {
			$b2 = 127;
			$hexLength = dechex($length);

			if (strlen($hexLength)%2 == 1) {
				$hexLength = '0' . $hexLength;
			} 
			$n = strlen($hexLength) - 2;
			for ($i = $n; $i >= 0; $i=$i-2) {
				$lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
			}

			while (strlen($lengthField) < 8) {
				$lengthField = chr(0) . $lengthField;
			}
		}
		return chr($b1) . chr($b2) . $lengthField . $message;
	}

	//
	// Unmask incoming framed message
	//
	private function s_unmask($text) {
		$length = ord($text[1]) & 127;
		if($length == 126) {
			$masks = substr($text, 4, 4);
			$data = substr($text, 8);
		}
		elseif($length == 127) {
			$masks = substr($text, 10, 4);
			$data = substr($text, 14);
		}
		else {
			$masks = substr($text, 2, 4);
			$data = substr($text, 6);
		}
		$text = "";
		for ($i = 0; $i < strlen($data); ++$i) {
			$text .= $data[$i] ^ $masks[$i%4];
		}
		return $text;
	}//s_unmask
	
	
	//
	//	Open the UDP server socket as an internal function
	//
	private function s_uopen() {
		global $debug;
		global $log;
		global $rcv_daemon_port;
		global $udp_daemon_port;
		global $serverIP;
		
		$address= $serverIP;
		
		if ($debug > 0) $log->lwrite("s_uopen:: Opening UDP Socket on IP ".$address.":".$udp_daemon_port);
		
		$this->usock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		//if (!$this->usock)
        //	die('Unable to create AF_UNIX socket');
			
    	//if (!socket_set_option($this->usock, SOL_SOCKET, SO_BROADCAST, 1)) 		// Re-use the port, 
		if (!socket_set_option($this->usock, SOL_SOCKET, SO_REUSEADDR, 1)) 		// Re-use the port,
    	{ 
			$log->lwrite("s_uopen:: socket_set_option failed:: ".socket_strerror(socket_last_error($this->usock)) ); 
			return(-1); 
   		}
		// Set listen port on any address
		if (!socket_bind($this->usock, $address, $udp_daemon_port))
		{
			$log->lwrite("s_uopen:: socket_bind failed:: ".socket_strerror(socket_last_error($this->usock)));
			socket_close($this->usock);
			return (-1);
		}	

		if ($debug > 0) $log->lwrite("s_uopen:: receive socket opened ok on port: ".$udp_daemon_port);
		return(0);
	}
	
	//
	// s_urecv. 
	// Main UDP receiver code for all messages on all socket connections to clients
	// $this->read contains the modified read array of socket to read
	//
	public function s_urecv() {
		global $log;
		global $debug;
		global $udp_daemon_port;
		global $serverIP;
		
		$buf = '';
		$name = '';
		$len = 512;
		$usec = 10000;
		
		$i1=time();

		
		if (!is_resource($this->usock)) {
            $this->s_uopen();
        }

		if (!($ret=socket_recvfrom ($this->usock, 
								   $buf ,
								   $len , 
								   MSG_DONTWAIT , 
								   $name, 
								   $port
		)	)						)
		{	
			$sockerr = socket_last_error($this->usock);
			
			switch($sockerr) {
				
				case 11:							// EAGAIN
					$log->lwrite("s_urecv:: no message",2);
					return(-1);
				break;
				default:
					$log->lwrite("s_urecv:: ERROR: ".socket_strerror($sockerr),1);
					return(-1);
				break;
			}
		}
		if ($ret == 0) {
				$log->lwrite("s_urecv:: No Data to read".$name.":".$port,2);
				return(-1);
		}
		else {
			$log->lwrite("s_urecv:: Receiving from: ".$name.":".$port." buf: ".$buf,2);
			return($buf);
		}
		return(-1);	
	}// s_urecv	
	
	
	//
	//	Open the server socket as an internal function
	//
	private function s_open() {
		global $debug;
		global $log;
		global $rcv_daemon_port;
		global $serverIP;
		
		$address= $serverIP;
		//$address = gethostbyname($_SERVER['SERVER_NAME']);
		//$address = $_SERVER['SERVER_ADDR'];			// Open THIS server IP
		
		if ($debug > 0) $log->lwrite("s_open:: Opening Sockets on IP ".$address.":".$rcv_daemon_port);
		
		$this->rsock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    	if (!socket_set_option($this->rsock, SOL_SOCKET, SO_REUSEADDR, 1)) 		// Re-use the port, 
    	{ 
			$log->lwrite("s_open:: socket_set_option failed:: ".socket_strerror(socket_last_error($this->rsock)) ); 
			return(-1); 
   		}
		// Set listen port on any address
		if (!socket_bind($this->rsock, $address, $rcv_daemon_port))
		{
			$log->lwrite("s_open:: socket_bind failed:: ".socket_strerror(socket_last_error($this->rsock)));
			socket_close($this->rsock);
			return (-1);
		}	
		// 10 connections is enough? The silent Max is SOMAXCONN==18 on Raspberry Linux
		if (socket_listen($this->rsock, 10) === false) {
     		echo "s_open:: socket_listen() failed:: ".socket_strerror(socket_last_error($this->rsock));
			return (-1);
 		}

		if ($debug > 0) $log->lwrite("s_open:: receive socket opened ok on port: ".$rcv_daemon_port);
		return(0);
	}
	
	//
	// Close the socket, use the client key ckey as an index for the socket administration.
	// This comes in handy, as the array will still have relevant info about the socket
	// even if the peer has already closed the connection
	//
	public function s_close($ckey) {
		global $log;
		global $debug;
		$log->lwrite("s_close:: close socket for IP "
						 	.$this->sockadmin[$ckey]['ip'].":"
							.$this->sockadmin[$ckey]['port'],2);
		
		socket_close($this->sockadmin[$ckey]['socket']);
	}// s_close
	
	//
	//	Send a message to the peer
	//	The message may need to be encoded depending on the type of client connected
	//  The function standard does not take a socket argument, we expect the $this->ssock
	//  to be set in the receiving function.
	//
	public function s_send($cmd_pkg) {
		global $log;
		global $debug;
		
		if (!is_resource($this->ssock)) {
			$log->lwrite("s_send failed: socket this->ssock not open");
			return(-1);
        }
		socket_getpeername($this->ssock, $clientIP, $clientPort);
		//$akey = array_keys($this->clients, $this->ssock);
		//$ckey = $akey[0];
		if (false === ( $ckey = array_search($this->ssock, $this->clients))) {
			$log->lwrite("s_send:: ERROR Key not found for current socket. ip: ".$clientIP.":".$clientPort);
			return(-1);
		}
		else {
			$log->lwrite("s_send:: Key ".$ckey." found for current socket. ip: ".$clientIP.":".$clientPort,2);
		}
		// If this is a websocket, make sure to encode first
		if ($this->sockadmin[$ckey]['type'] == 'websocket')
		{
			$log->lwrite("s_send:: websocket, encoding message. ip: ".$clientIP.":".$clientPort,2);
			$message = $this->s_encode($cmd_pkg);
		}
		else {
			$log->lwrite("s_send:: Not a websocket. ".$clientIP.":".$clientPort,2);
			$message = $cmd_pkg;
		}
		//
		$log->lwrite("s_send:: writing message <".$cmd_pkg.">",3);				
    	if (socket_write($this->ssock, $message, strlen($message)) === false)
   		{
     		$log->lwrite( "s_send:: socket_write failed:: ".socket_strerror(socket_last_error()) );
			socket_close($this->ssock);					//  This is one of the accepted connections
			return(-1);
    	}
		$log->lwrite("s_send:: socket_write to IP: ".$clientIP.":".$clientPort." success",1);
		return(0);
	}// s_send
	
	//
	// Broadcast a message to every connected (web)socket. As normal socket will probably
	// not be async
	//
	public function s_bcast($cmd_pkg) {
		global $log, $debug;
		
		$log->lwrite("s_bcast:: writing to connected clients: <".$cmd_pkg.">",2);
				
		foreach ($this->clients as $key => $client) 
		{  
			if (!is_resource($client)) {
				$log->lwrite("ERROR s_bcast:: failed: socket client not open: ".
							 $this->sockadmin[$key]['ip'].":".
							 $this->sockadmin[$key]['port']
				);
				socket_close($client);					//  This is one of the accepted connections
				unset($this->clients[$key]);
				unset($this->sockadmin[$key]);
				continue;
        	}
			if ($this->sockadmin[$key]['type'] != 'websocket' ) {
				$log->lwrite("s_bcast:: Warning: Not a websocket: "
									.$this->sockadmin[$key]['ip'].":"
									.$this->sockadmin[$key]['port'].", type "
									.$this->sockadmin[$key]['type']." need upgrade?"
									,2);
				$message = $cmd_pkg;
				if (socket_write($client,$message,strlen($message)) === false)
   				{
     				$log->lwrite( "ERROR s_bcast:: write failed:: ".socket_strerror(socket_last_error()) );
					socket_close($client);					//  This is one of the accepted connections
					unset($this->clients[$key]);
					unset($this->sockadmin[$key]);
					continue;
    			}
		
    			$log->lwrite("s_bcast:: raw socket_write to IP: ".$this->sockadmin[$key]['ip'].
										":".$this->sockadmin[$key]['port']." success",2);
			}
			else // Encode the message according to websocket standard
			{
				$message = $this->s_encode($cmd_pkg);
				if (socket_write($client,$message,strlen($message)) === false)
   				{
     				$log->lwrite( "ERROR s_bcast:: write failed:: ".socket_strerror(socket_last_error()) );
					socket_close($client);					//  This is one of the accepted connections
					unset($this->clients[$key]);
					unset($this->sockadmin[$key]);
					continue;
    			}
				$log->lwrite("s_bcast:: web socket_write to IP: ".$this->sockadmin[$key]['ip'].
										":".$this->sockadmin[$key]['port']." success",2);
			}
		}
		return(0);
	}// s_bcast
	
	
	//
	// s_recv. Main receiver code for all messages on all socket connections to clients
	// $this->read contains the modified read array of socket to read
	//
	public function s_recv() {
		global $log;
		global $debug;
		global $rcv_daemon_port;
		global $serverIP;
		
		$buf = '';
		$clientPort=0;
		$clientIP=0;
		$usec = 10000;
		
		// Start with reading the UDP socket
		if ( ($buf = $this->s_urecv() ) != -1 ) {
			$log->lwrite("s_recv:: UDP s_urecv returned buffer: ".$buf,3);
			return($buf);
		}
		
		$i1=time();

		$log->lwrite("s_recv:: calling socket_select, timeout: ".$this->wait,3);
		if (!is_resource($this->rsock)) {
            $this->s_open();
        }
		
		// Wait for activities on the set of sockets in ->read, which includes the general server socket
		$ret = socket_select($this->read, $write = NULL, $except = NULL, $this->wait, $usec);
		if ($ret === false)
     	{
        	$log->lwrite( "s_recv:: socket_select failed:: ".socket_strerror(socket_last_error())."\n");
			return(-1);
     	}
		
		// Print data for changed sockets, this is for debugging only
		if ($debug>=3) {
			$log->lwrite("s_recv:: socket_select: printing changed sockets");
			foreach ($this->read as $key => $client) 
			{
				socket_getpeername($client, $clientIP, $clientPort);
				$log->lwrite("s_recv:: socket_select: key: ".$key." listen ip: ".$clientIP.":".$clientPort);
			}
		}
		
		// It the select function returns 0, there are no messages on any read sockets
		// and we return to the calling main process
		if ($ret == 0) {
			$log->lwrite( "s_recv:: socket_select returned 0",3);
			return(-1);
		}
		// $ret contains the number of sockets with messages. We will only serve one at a time!!
		$log->lwrite("s_recv:: socket_select success, returned: ".$ret,3);
		
		// New connections? (coming from a previous call of socket_select() in $read)
		// Incoming connect request comes in on server socket rsock only
		$log->lwrite("s_recv:: checking for new connections in this->read",2);
		if (in_array($this->rsock, $this->read)) 
		{
			$log->lwrite("s_recv:: server rsock to accept new connection ",3);
			if (($msgsock = socket_accept($this->rsock)) === false) {
            	$log->lwrite("s_recv:: socket_accept() failed:: ".socket_strerror(socket_last_error($this->rsock)) );
            	return(-1);
        	}

			socket_getpeername($msgsock, $clientIP, $clientPort);
			$log->lwrite("s_recv:: socket_accept: connect ip: ".$clientIP.":".$clientPort,1);
			
			// Add this socket to the array of clients for this server 
			// and update the admin array with relevant info for this socket
			$this->clients[] = $msgsock;
			$s_admin = array (
								  'key' => '',
								  'type' => 'rawsocket' , // either { rawsocket, websocket, ack }
								  'socket' => $msgsock ,
								  'ip' => $clientIP ,
								  'port' => $clientPort ,
								  'login' => '',
								  'trusted' => '0'
							);
			
			// Can we trust this socket -> Is the client on our subnetwork?
			if (clientInSameSubnet($clientIP)) {
				$log->lwrite("s_recv:: client IP: ".$clientIP." in local subnet",2);
				$s_admin['trusted']	= '1';
			}
			
			// Append to Admin Array
			$this->sockadmin[] = $s_admin;
			
			// remove rsock from read
			$key = array_search($this->rsock, $this->read);
			if (false === $key)
				$log->lwrite("s_recv:: ERROR: unable to find key: ".$key." in the read array");
			else {
				$log->lwrite("s_recv:: Masking rsock from read array, key: ".$key,3);
				unset($this->read[$key]);
			}
		}
		
		// Handle incoming messages. Messages com in on one of the sockets we connected to.
		// As we handle incoming messages immediately. Set the sender socket in private var
		// so the s_Send command knows where to send response to for last message
		
		$log->lwrite("s_recv:: checking for data on sockets of this->read",3);
		foreach ($this->read as $key => $client) 
		{
				$akey = array_keys($this->clients, $client);	// Key in the client array (and admin array)
				$ckey = $akey[0];

				$log->lwrite("s_recv:: key: ".$key." (ckey = ".$ckey.") has data",3);
				$this->ssock = $client;							// Send replies for client to this address
				
				$buf = @socket_read($client, 2048, PHP_BINARY_READ);
				if ($buf === false ) 
				{
					// Error,  close socket and display message
					$err = socket_last_error($client);
					
					if ($err === 104) {
						$log->lwrite("s_recv:: socket_read failed: ".$this->sockadmin[$ckey]['ip']." - Connection reset by peer");		
						$this->s_close($ckey);
					}
					else {
						$log->lwrite("s_recv:: socket_read failed: ".socket_strerror($err));
					}
					$log->lwrite("s_recv:: socket marked unset: ".$key." error: ".$err,3);
					// We need to find the key in clients and NOT in read!!!
					unset($this->clients[$ckey]);
					unset($this->sockadmin[$ckey]);
					continue;
				}
				
				// Select returns client with empty messages, means closed connection
				//
				else 
				if (empty($buf)) {
					socket_getpeername($client, $clientIP, $clientPort);
					$log->lwrite("s_recv:: buffer empty for key: ".$key.", IP".$clientIP.":".$clientPort,3);
					// empty read means..... should be closing socket....
					$this->s_close($ckey);
					unset($this->clients[$ckey]);
					unset($this->sockadmin[$ckey]);
					continue;
				}
				
				// Websockets send a header back upon connect to upgrade the connection
				// First see if this is a websocket request, and do the upgrade connection. 
				// First characters are 'GET' for Websockets
				//
				if (substr($buf,0,3) == "GET" ) {
					$this->sockadmin[$ckey]['type'] = 'websocket';
					socket_getpeername($client, $clientIP, $clientPort);
					$log->lwrite("s_recv:: Upgrade request for ".$this->sockadmin[$ckey]['ip'].":".$this->sockadmin[$ckey]['port'],3);
					$log->lwrite("s_recv:: Upgrade request for ".$clientIP.":".$clientPort." \n".$buf." ",3);
					$this->s_upgrade($buf, $client, $serverIP, $rcv_daemon_port); //perform websocket handshake
					continue;
				}
				
				// If this is an upgraded connection, use s_unmask and json_decode to view buffer
				//
				$log->lwrite("s_recv:: ckey: ".$ckey.", clientIP: ".$clientIP,3);
				$log->lwrite("s_recv:: ckey: ".$ckey.", this clientIP: ".$this->clientIP,3);
				$log->lwrite("s_recv:: sockettype: ".$this->sockadmin[$ckey]['type'],3);
				$log->lwrite("s_recv:: sockadmin ip: ".$this->sockadmin[$ckey]['ip'].", trusted:".$this->sockadmin[$ckey]['trusted'],3);
				
				$this->clientIP = $this->sockadmin[$ckey]['ip'];
				if ($this->sockadmin[$ckey]['type'] == 'websocket' ) 
				{
					$ubuf = $this->s_unmask($buf);
					return($ubuf);							// json array object
				}
				
				// type must be a rawsocket
				else if ($this->sockadmin[$ckey]['type'] == 'rawsocket' ) 
				{
					if ($debug>2) {
							$i2=time();
							socket_getpeername($client, $clientIP, $clientPort);
							$log->lwrite("s_recv:: Raw buf from IP: ".$clientIP.":".$clientPort
									.", buf: <".$buf.">, in ".($i2-$i1)." seconds");
					}
					return($buf);
				}
				
				// Unknown type (I guess)
				else {
					$i2=time();
					$log->lwrite("ERROR s_recv:: Unknown type buf ".$this->sockadmin[$ckey]['type']."from IP: ".$clientIP.":".$clientPort
									.", buf: <".$buf.">, in ".($i2-$i1)." seconds",2);
				}
		}//for
		return(-1);	
	}// s_recv
	

	
	// Do we trust the current client?
	//
	//
	public function s_trusted() {
		global $debug;
		global $log;
		
		$akey = array_keys($this->clients, $this->ssock);
		if (count($akey) == 0) {
			$log->lwrite("s_trusted:: Socket not present anymore in client array",3);
			return(0);
		}
		$ckey = $akey[0];
		$log->lwrite("s_trusted:: ckey: ".$ckey." checking clientIP: ".$this->clientIP,3);
		$log->lwrite("s_trusted:: ckey: ".$ckey." checking sockadmin IP: ".$this->sockadmin[$ckey]['ip'],3);
		$log->lwrite("s_trusted:: ckey: ".$ckey." checking sockadmin Trusted: ".$this->sockadmin[$ckey]['trusted'],3);
		if (( $this->sockadmin[$ckey]['trusted'] == "1" ) ||
			( $this->clients[$ckey]['socket'] == $this->rsock) ||				 
			( $this->clientIP == "127.0.0.1") ) 
		{
			$log->lwrite("s_trusted returned success for IP ".$this->sockadmin[$ckey]['ip'],3);
			return(1);						// trust
		}
		return(0);
	}
	
	// This function ONLY sets the wait time for the next SELECT call
	// and prepares the listening structure for the SELECT call
	//
	public function s_wait($sec) {
		global $debug;
		global $log;
		global $interval;
		
		if (!is_resource($this->rsock)) {	// Not really necessary now
            $this->s_open();
        }
		$this->read = array();
		$this->read[] = $this->rsock;
		$this->read = array_merge($this->read,$this->clients);
		
		// Could be that due to longer execution the first queue item timed by qtime should
		// already be running. Su qtime is 0 or even -1 or so. In this case make it 0, and usecs 100.
		if ($sec < 0) $sec=0;
		if ($sec > $interval) $sec = $interval;
		
		$log->lwrite( "s_wait:: set wait to ".$sec." seconds",3);
		$this->wait = $sec;
		return(0);
	}//s_wait
	
} //class Sock


/* -----------------------------------------------------------------------------------
* CLASS DEVICE 
* 
*  This class contains all device related functions such as "add", "update", "delete", "get"
* We need device functions to update the status of devices once the daemon starts executing
* commands in the queue.
*
* The client will only see these changes if the page is reloaded or devices are reloaded for
* some reason.
*/
class Device {
	private $d_list = [];
	private $mysqli;
	
	// SQL connection remains open during the daemon running. 
	private function sql_open() {
		global $log;
		global $dbuser, $dbpass, $dbname, $dbhost;
		$this->mysqli = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
		if ($this->mysqli->connect_errno) {
			$log->lwrite("sql_open:: failed to connect to MySQL: (".$this->mysqli->connect_errno.") ".$this->mysqli->connect_error);
			return (-1);
		}
		return(0);
	}
	
	// Add a new device record/object
	//
	public function add() {
		global $log;
		if (!is_resource($this->mysqli)) {
            $this->sql_open();
        }
	}
	
	// Lookup by id
	//
	public function get($room_id, $dev_nr) {
		global $debug, $log;
		if (!is_resource($this->mysqli)) {
            $this->sql_open();
        }
		$dev_id = "D".$dev_nr;
		$log->lwrite("get:: room: ".$room_id.", dev: ".$dev_id,2);
		
		$sqlCommand = "SELECT * FROM devices WHERE id='$dev_id' AND room='$room_id'";
		$query = mysqli_query($this->mysqli, $sqlCommand) or die (mysqli_error());
		while ($row = mysqli_fetch_assoc($query)) { 
			$log->lwrite("get:: found device: ".$row['name'],2);
			return($row) ;
		}
		$log->lwrite("get:: Did not find device");
		mysqli_free_result($query);
	}
	
	// update device object in sql
	//
	public function upd($device) {
		global $log, $devices;
		if (!is_resource($this->mysqli)) {
            $this->sql_open();
        }
		// Write to internal structure
		
		// Write to SQL
		$query = "UPDATE devices SET val='".$device['val']."', 
									lastval='".$device['lastval']."', 
									name='".$device['name']."', 
									brand='".$device['brand']."' 
				WHERE room='$device[room]' AND id='$device[id]' " ;
		if (!mysqli_query($this->mysqli, $query))
		{
			$log->lwrite( "upd:: mysqli_query error" );
			return (-1);
		}
		return(0);
	}
	
	// Delete a device XXX not yet implemented
	//
	public function del($device) {
		global $log;
		if (!is_resource($this->mysqli)) {
            $this->sql_open();
        }
	}
}



/* -----------------------------------------------------------------------------------
  Load the scene(s_ with 'name' from the SQL database
  
  We start readingthe scene as soon as we determine that it is time to start a
  command based o timer settings. If so, we lookup the scene and its seq(uence)
  element. The scene['seq'] contains the string of commands to be sent to the 
  devices......
  
  We read the database scenes and determine if action need to be taken.
  NOTE: Scene names and timer names need to be unique.
 -------------------------------------------------------------------------------------*/
function load_scenes()
{
	global $log, $debug;
	global $appmsg, $apperr;
	global $dbname, $dbuser, $dbpass, $dbhost;
	
	$config = array();
	$scenes = array();

	// We need to connect to the database for start
	$mysqli = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
	if ($mysqli->connect_errno) {
		$log->lwrite("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
		return(-1);
	}
	
	$sqlCommand = "SELECT * FROM scenes";
	$query = mysqli_query($mysqli, $sqlCommand) or die (mysqli_error());
	while ($row = mysqli_fetch_assoc($query)) { 

		$scenes[] = $row ;
	}
	// There will be an array returned, even if we only have ONE result
	mysqli_free_result($query);
	mysqli_close($mysqli);
	
	if (count($scenes) == 0) {	
		return(-1);
	}
	return($scenes);								// Return all scenes
}



// ------------------------------------------------------------------------------------
// Find a single name in the scene database array opject.
function load_scene($name)
{
	global $log, $debug;
	$scenes = load_scenes();
	if (count($scenes) == 0) return(-1);

	for ($i=0; $i<count($scenes); $i++) {
		
		if ($scenes[$i]['name'] == $name) return($scenes[$i]);
	}
	// If there is more than 1 result (impossible), we return the first result
	return(-1);
}


/* -----------------------------------------------------------------------------------
  Load the array of timers from the SQL database into a local array object
  
  Communication between the running program and this backend daemon is done solely
  based on MySQL database content. In a later version, we might work with sockets 
  also.
  
  We read the database timers and determine if action need to be taken.
 -------------------------------------------------------------------------------------*/
function load_timers()
{
	$config = array();
	$timers = array();
	
 	// We assume that a database has been created by the user
	global $dbname;
	global $dbuser;
	global $dbpass;
	global $dbhost;
	
	// We need to connect to the database for start
	$mysqli = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
	if ($mysqli->connect_errno) {
		$log->lwrite("Failed to connect to MySQL: (".$mysqli->connect_errno.") ".$mysqli->connect_error);
		return(-1);
	}
	
	$sqlCommand = "SELECT id, name, scene, tstart, startd, endd, days, months, skip FROM timers";
	$query = mysqli_query($mysqli, $sqlCommand) or die (mysqli_error());
	while ($row = mysqli_fetch_assoc($query)) { 
		$timers[] = $row ;
	}
	
	mysqli_free_result($query);
	mysqli_close($mysqli);
	return($timers);
}

/* -----------------------------------------------------------------------------------
  Load the array of handsets from the SQL database into a local array object
  
  Communication between the running program and this backend daemon is done solely
  based on MySQL database content. In a later version, we might work with sockets 
  also.
  
  We read the database handses and determine if action need to be taken.
 -------------------------------------------------------------------------------------*/
function load_handsets()
{
	global $appmsg;
	global $apperr;
	global $debug;
	global $log;
 	// We assume that a database has been created by the user
	global $dbname;
	global $dbuser;
	global $dbpass;
	global $dbhost;
	
	$config = array();
	$handsets = array();
	
	// We need to connect to the database for start
	$mysqli = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
	if ($mysqli->connect_errno) {
		$log->lwrite("load_handsets:: Failed to connect to MySQL: (".$mysqli->connect_errno . ") ".$mysqli->connect_error);
		return(-1);
	}
	
	$sqlCommand = "SELECT * FROM handsets";
	$query = mysqli_query($mysqli, $sqlCommand) or die (mysqli_error());
	while ($row = mysqli_fetch_assoc($query)) { 
		$handsets[] = $row ;
	}
	if ($debug>1) $log->lwrite("load_handsets:: loaded MySQL handsets object");
	
	mysqli_free_result($query);
	mysqli_close($mysqli);
	return($handsets);
}


/* -----------------------------------------------------------------------------------
* CLASS WEATHER
* 
* This class contains weather related functions such as "add", "update", "delete", "get"
* We need weather functions to update the status of weather sensors once the daemon  
* starts executing commands in the queue.
*
* The client will only see these changes if the page is reloaded or weather are
* reloaded for some reason.
*/
class Weather {
	private $w_list = [];
	private $mysqli;
	
	// SQL connection remains open during the daemon running. 
	private function sql_open() {
		global $log;
		global $dbuser, $dbpass, $dbname, $dbhost;
		$this->mysqli = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
		if ($this->mysqli->connect_errno) {
			$log->lwrite("sql_open:: failed to connect to MySQL: ("
						.$this->mysqli->connect_errno.") "
						 .$this->mysqli->connect_error);
			return (-1);
		}
		return(0);
	}
	
	// Add a new device record/object
	//
	public function add() {
		global $log;
		if (!is_resource($this->mysqli)) {
            $this->sql_open();
        }
		// XXX Not necessary
	}
	
	// Lookup by address and channel combination
	//
	public function get($address, $channel) {
		global $debug, $log;
		if (!is_resource($this->mysqli)) {
            $this->sql_open();
        }
		
		if ($debug>1) $log->lwrite("get:: room: ".$address.", dev: ".$channel);
		
		$sqlCommand = "SELECT * FROM weather WHERE address='$address' AND channel='$channel'";
		$query = mysqli_query($this->mysqli, $sqlCommand) or die (mysqli_error());
		while ($row = mysqli_fetch_assoc($query)) { 
			if ($debug>1) $log->lwrite("get:: found weather sensor: ".$row['name']);
			return($row) ;
		}
		$log->lwrite("get:: Did not find weather");
		mysqli_free_result($query);
	}
	
	// update device object in sql
	//
	public function upd($w) {
		global $log, $weather;
		if (!is_resource($this->mysqli)) {
            $this->sql_open();
        }
		// Write to log
		$log->lwrite("Weather upd:: address: ".$w['address'].", channel: ".$w['channel'].", temperature: "
											.$w['temperature'].", humidity: ".$w['humidity']);

		// Write to SQL
		$query = "UPDATE weather SET 
									temperature='".$w['temperature']."',
									humidity='".$w['humidity']."',
									windspeed='".$w['windspeed']."',
									winddirection='".$w['winddirection']."',
									rainfall='".$w['rainfall']."' 
												   
				WHERE address='$w[address]' AND channel='$w[channel]' " ;
				
		if (!mysqli_query($this->mysqli, $query))
		{
			$log->lwrite( "Weather upd:: mysqli_query error" );
			return (-1);
		}
		return(0);
	}
	
	// Delete a device XXX not yet implemented
	//
	public function del($weather) {
		global $log;
		if (!is_resource($this->mysqli)) {
            $this->sql_open();
        }
		// XXX NOt implemented yet
	}
}// class Weather




/*	-------------------------------------------------------
	function post_parse()
	Get into the $_POST to see if there is more work....
	
	-------------------------------------------------------	*/
function post_parse()
{
  global $appmsg, $apperr, $debug, $action, $icsmsg;
	if (empty($_POST)) { 
		// $log->lwrite("No _POST commands");
		return(-1);
	}
	foreach ( $_POST as $ind => $val )
	{
		switch ( $ind )
		{
			case "action":
				$action = $val;
			break;	
			case "message":
				$icsmsg = $val;
//				$value  = json_encode($val);
			break;		
		} // switch $ind
	} // for
} // function


/*	--------------------------------------------------------------------------------	
	function get_parse. 
	Parse the $_GET  for commands
	Commands may be lamp, load, message, style, debug
		In case of lamp, message contains its parameters
	--------------------------------------------------------------------------------	*/
function get_parse() 
{
  global $appmsg, $apperr, $action, $icsmsg;
  global $log;
  foreach ($_GET as $ind => $val )
  {
    $log->lwrite ("get_parse:: index: $ind and val: $val<br>");
    switch($ind) 
	{
	case "action":
		$action = $val;
	break;
	case "message":
		$icsmsg = json_decode($val);
		$apperr .= "\n ics: " . $icsmsg;
	break;
    } //   Switch ind
  }	//  Foreach
  return(0);
} // Func



/* ---------------------------------------------------------------------------------
* Console_Message
*
* Parse a message coming from the outside world on a socket.
* and parse it for commands. Commands are console/management action
* 
*/
function console_message($request) {
	global $log;
	global $sock;
	
	$ret = "";
	switch($request) {
		
		case 'clients':
			foreach ($sock->clients as $key => $val ) {
				$ret .= "IP: ".$sock->sockadmin[$key]['ip'].":".$sock->sockadmin[$key]['port'];
				$ret .=	", type ".$sock->sockadmin[$key]['type']."\n";
				// $ret .= 
			}
		break;
		
		case 'logs':
			$ret .= "Logdata for the console file is now fake generated";
		break;
		
		case 'rebootdaemon':
			$ret .= "Reboot the daemon process";
		break;
		
		default:
			$ret = "Not recognized: <".$request.">";
		break;
	}
	return($ret);
}


/* ---------------------------------------------------------------------------------
* MESSAGE_PARSE a socket
*
* Parse a message coming from the outside world on a socket.
* and parse it for commands. Commands may either be events for the run Queue
* or changes to the database/settings that will influence the timer
* 
* XXX This function could/needs to be extended so it can parse json too
*
* Commands:
*	!RxDyFz, where x is room number 1-16, y is device number 0-31 and z is
*			either 1 (On) or 0 (Off)
*	!AxxxxxxxxDyFz, Handset command, arriving on Address xxxx and for button
*			pair y with value z (0 or 1)
*	!FqP"scene", Run scene with the name "scene"
*
*/
function message_parse($cmd) {
	
	global $debug, $log, $queue;
	global $devices, $scenes, $handsets;

	// XXX use json to properly receive commands?
	$tim = time();
	switch ( substr($cmd,0,2) ) 
	{
	// All scene and timer commands start with !F
	case '!F':
	
		switch ( substr($cmd,2,2) )
		{
		// Queue and start a scene
		case "qP":
			$scene_name = substr($cmd, 5, -1);					// Get rid of quotes around name 
			$log->lwrite("parse:: FqP Scene Queue cmd: ".$scene_name,1);
			
			$scene = read_scene($scene_name);
			if ($scene == -1) { 								// Command not found in database
				$log->lwrite("parse:: Cannot find scene: ".$scene_name." in the database");
				break; 
			}
			$scene_seq = $scene['seq'];
			if ($scene_seq == "") {
				$log->lwrite("parse:: Scene: ".$scene_name." found, but sequence empty (must save first)");
				break;
			}
			$log->lwrite("parse:: Scene found, reading sequence: ".$scene_seq);
			// Split the Scene sequence into 2 elements separated bu comma (!RxxDxxFxyy,hh:mm:ss);
			$splits = explode(',' , $scene_seq);
			
			for ($i=0; $i< count($splits); $i+=2) {
				// $cmd = $splits[$i];
				// If $cmd is a ALL OFF command, we need to substitue the command wit
				// a string of device commands that need to be switche off.....
				
				$log->lwrite("cmd  : " . $i . "=>" . $splits[$i],2);
				$log->lwrite("timer: " . $i . "=>" . $splits[$i+1],2);
				
				list ( $hrs, $mins, $secs ) = explode(':' , $splits[$i+1]);
				$log->lwrite("Cmnd wait for $hrs hours, $mins minutes and $secs seconds",1);
				
				// sleep(($hrs*3600)+($mins*60)+$secs);
				// We cannot sleep in the background now, it will give a timeout!!
				// we'll have to implement something like a timeer through cron or so
				$item = array(
					'action'=> "gui",
    				'scene' => $scene_name,
    				'cmd'   => $splits[$i],
					'secs'  => ($hrs*3600)+($mins*60)+$secs+ $tim
   				);
				$queue->q_insert($item);
				$queue->q_print();
			}
		break;
		
		// Store a Scene in the database
		case "eP":	
			$log->lwrite("parse:: FeP Scene Store cmd: ".$cmd,1 );
		break;
		
		case "cP":
			$log->lwrite("parse:: FcP Scene Cancel cmd: ".$cmd,1 );
		break;
		
		case "xP":
			$log->lwrite("parse:: FxP Scene Delete cmd: ".$cmd,1 );
		break;
		
		default:
			$log->lwrite("parse:: Daemon does not recognize command: ".$cmd,1 );
		}//switch(2,2)
	break;	
	//	
	// All room and device commands start with !R
	// These room commands are received from the client application.
	//
	case "!R":
		list( $room, $value ) = sscanf ($cmd, "!R%dF%s" );
		if ($debug>1) $log->lwrite("parse:: Room: ".$room.", Value: ".$value);
		if (substr($value,0,1) == "a") {
			// All OFF
			if ($debug>0) $log->lwrite("parse:: All OFF:: Room: ".$room.", Val: ".$value);
			for ($i=0; $i< count($devices); $i++) {
				if ($devices[$i]['room'] == $room ) {
					$devices[$i]['val']=0;
					// We expand the Fa command, look up all devices in that room and
					// insert a F0 OFF command for every device in the queue
					$item = array(
    					'scene' => "",
						'action' => "gui",
    					'cmd'   => "!R".$room.$devices[$i]['id']."F0",
						'secs'  => time()
   					);
					$queue->q_insert($item);
				}
			}
			break;
		}
	
		list( $room, $dev, $value ) = sscanf ($cmd, "!R%dD%dF%s" );
		if ($debug>1) $log->lwrite("parse:: Room: ".$room.", Device: ".$dev.", Value: ".$value);	
		if (substr($value,0,1) == "0") {
			// Device OFF
			$log->lwrite("parse:: Device OFF:: Room: ".$room.", Dev: ".$dev.", Val: ".$value);
			$item = array(
    			'scene' => "",
				'action' => "gui",
    			'cmd'   => $cmd,
				'secs'  => time()
   			);
			$queue->q_insert($item);
			break;
		}
		else if (substr($value,0,1) == "1") {
			// Device ON
			$log->lwrite("parse:: Device ON:: Room: ".$room.", Dev: ".$dev.", Val: ".$value);
			$item = array(
    			'scene' => "",
				'action' => "gui",
    			'cmd'   => $cmd,
				'secs'  => time()
   			);
			$queue->q_insert($item);
			break;
		}
		else {
			// Device Dim, skip first 2 chars
			$log->lwrite("parse:: Device Dim:: Room: ".$room.", Dev: ".$dev.", Val: ".substr($value,2));
			$item = array(
    			'scene' => "",
				'action' => "gui",
    			'cmd'   => $cmd,
				'secs'  => time()
   			);
			$queue->q_insert($item);
			break;
		}
	break;
	
	// All handsets and remote commands start with an address of the device
	// Message format: "!AxxxxxxxxDyyGz" for Group code "!AxxxxxxxxDyyFz for Device codes"
	// We do NOT accept dimmers at the moment, although we could do something
	// when we receive a wireless signal from another device.
		
	case "!A":
	
		list( $address, $dev, $value ) = sscanf ($cmd, "!A%dD%dF%s" );
		if ($debug>1) $log->lwrite("parse:: Address: ".$address.", Dev: ".$dev.", Value: ".$value);
		
		// Lookup the address, unit, value combination in array $handsets
		// If we find the corresponsing scene, execute that scene by calling this function again
		// recursively
		if (($handsets = load_handsets()) == -1) {
			$log->lwrite("parse:: load handsets failed");
		}
		if ($debug>2) $log->lwrite("parse:: load handsets success, count: ".count($handsets) );
		
		for ($i=0; $i<count($handsets); $i++) 
		{	
			if ($debug>2) $log->lwrite("parse:: Handset ".$handsets[$i]['addr'].":".$handsets[$i]['unit'].":".$handsets[$i]['val']." found, scene: ".$handsets[$i]['scene']);
			
			// If the handset matches with the address, unit and the value (=button pressed) 
			// then execute the scene
			if ( ($handsets[$i]['addr'] == $address) &&
				 ($handsets[$i]['unit'] == $dev ) &&
				 ($handsets[$i]['val'] == $value ))
			{
				$splits = explode(',' , $handsets[$i]['scene']);
			
				for ($j=0; $j< count($splits); $j+=2) {
				
					// If $cmd is a ALL OFF command, we need to substitue the command wit
					// a string of device commands that need to be switched off.....
				
					if ($debug > 1) $log->lwrite("cmd  : " . $j . "=>" . $splits[$j]);
					if ($debug > 1) $log->lwrite("timer: " . $j . "=>" . $splits[$j+1]);
				
					list ( $hrs, $mins, $secs ) = explode(':' , $splits[$j+1]);
					$log->lwrite("parse:: Cmd wait for $hrs hours, $mins minutes and $secs seconds");
				
					// sleep(($hrs*3600)+($mins*60)+$secs);
					// We cannot sleep in the background now, it will give a timeout!!
					// we'll have to implement something like a timeer through cron or so
					$item = array(
    					'scene' => $handsets[$i]['name'],
						'action' => "gui",
    					'cmd'   => $splits[$j],
						'secs'  => ($hrs*3600)+($mins*60)+$secs+ $tim
   					);
					$queue->q_insert($item);
					$queue->q_print();
				}//for all splits
				
			}//if addres match
		}//for count handsets
		$log->lwrite("parse:: !A message finished");
		
	break;
	
	case "!Q":
		//if (substr($value,0,1) == "0") {
			// Group OFF
			//if ($debug>0) $log->lwrite("parse:: All OFF:: Room: ".$room.", Val: ".$value);
			//for ($i=0; $i< count($devices); $i++) {
				//if ($devices[$i]['room'] == $room ) {
					//$devices[$i]['val']=0;
					// We expand the Fa command, look up all devices in that room and
					// insert a F0 OFF command for every device in the queue
					//$item = array(
    				//	'scene' => "",
    				//	'cmd'   => "!R".$room.$devices[$i]['id']."F0",
					//	'secs'  => time()
   					//);
					//$queue->q_insert($item);
				//}
			//}
			//break;
		//}//if
	
		list( $address, $dev, $value ) = sscanf ($cmd, "!G%dD%dG%s" );
		if ($debug>1) $log->lwrite("parse:: Address: ".$address.", Device: ".$dev.", Value: ".$value);	
		if (substr($value,0,1) == "0") {
			// Device OFF
			$log->lwrite("parse:: Device OFF:: Address: ".$address.", Dev: ".$dev.", Val: ".$value);
			$item = array(
    			'scene' => "",
				'action' => "gui",
    			'cmd'   => $cmd,
				'secs'  => time()
   			);
			$queue->q_insert($item);
			break;
		}
		else if (substr($value,0,1) == "1") {
			// Device ON
			$log->lwrite("parse:: Device ON:: Address: ".$address.", Dev: ".$dev.", Val: ".$value);
			$item = array(
    			'scene' => "",
				'action' => "gui",
    			'cmd'   => $cmd,
				'secs'  => time()
   			);
			$queue->q_insert($item);
			break;
		}
	break;
	
	default:
		$log->lwrite("parse:: Command not recognized: ".$cmd);
	
	}//switch cmd substr(0,2)
}//function


/*	==============================================================================	
*	MAIN PROGRAM
*
* The main program and control flow is as follows:
* 
* 0. Initially the code initializes variables and parses the commandline for special commands
* 1. The communication socket is read, and incoming messages are parsed. the messages can be:
*	- Simple commands for devices (on/off/dimlevel)
*	- Group commands such as ALL OFF in a room
*	- Scene execution commands
*   The commands received are inserted in a queue, with timing for execution
*
* 2. We execute the queue for commands that need execution based on their timing info
*	- Some information sich as the brand is retrieved during queue execution
*
* 3. We read the timers database to see if there are timers that fire. 
*	- If a timer expires, we lookup the corresponding scene
*	- We decompose/explode the commandstring of the scene into separate command/time pairs
*	- Each command/timer pair is inserted in the queue
*
*	==============================================================================	*/
$ret = 0;
set_time_limit();							// NO execution time limit imposed
ob_implicit_flush();

$log = new Logging();						// Logging class initialization
$wlog = new Logging();						// Weather Log

$queue = new Queue();
$sock = new Sock();
$dlist = new Device();						// Class for handling of device specific commands
$wthr = new Weather();						// Class for Weather handling in database

// set path and name of log file (optional)
$log->lfile('/home/pi/log/LamPI-daemon.log');
$wlog->lfile('/home/pi/log/LamPI-weather.log');


$log->lwrite("-------------- STARTING DAEMON ----------------------");

// Parse the $_GET (starting) or even the $_POST for commandparameters
	// 1. Parse the URL sent by client (not working, but could restart itself later version)
	// post_parse will parse the commands that are sent by the java app on the client
	// $_POST is used for data that should not be sniffed from URL line, and
	// for changes sent to the devices

	$ret = get_parse();
	if ($ret != -1 )
	{
		// Do Processing
		// XXX Needs some cleaning and better/consistent messaging specification
		// could also include the setting of debug on the client side
		switch($action)
		{
			case "load":
				$log->lwrite("Calling load: par: ".$icsmsg."\n");
				$appmsg = load_database();
				$ret = 0;
			break;
		
			case "setTimer":
				$apperr .= "Calling setTimer: par: $icsmsg\n";
				//$appmsg = load_database();
				$apperr .= "\nsetTimer OK: $appmsg[rooms][1]";
				$ret = 0;
			break;
	
			case "store":
				// What happens if we receive a complex datastructure?
				$apperr .= "Calling store: par: $icsmsg\n";
				$appmsg = store_database($icsmsg);
				$apperr .= "\nStore OK";
				$ret = 0;
			break;
	
			default:
				$appmsg .= "action: " . $action;
				$log->lwrite("main:: parse default, no command or command not recognized");
				$ret = 0; 
		}
		if ($ret > -1) {
			$send = array(
   				'tcnt' => $ret,
				'appmsg'=> $appmsg,
   	 			'status' => 'OK',
				'apperr'=> $apperr,
    		);
			$output=json_encode($send);
		}
		else {	
			$apperr .= "ics_cmd returns error code";
			$send = array(
    			'tcnt' => $ret,
				'appmsg'=> $appmsg,
    			'status' => 'ERR',
				'apperr' => $apperr,
    		);
			$output=json_encode($send);
		}
		if ($debug>1) $log->lwrite ( $output . " at " . date("l") . "\n" )  ;
	}
	else {
		if ($debug>0) $log->lwrite("No GET Commands " . date("l") . "\n");
	}
	
// Start with loading the database into a local $config structure. This takes 
// time once , but once we are in the loop it will save us time every loop.

$log->lwrite("main:: Loading the database");
$config = load_database();					// load the complete configuration object
if ($config == -1) 
{
	$log->lwrite("main:: Error loading database, error: ".$apperr);
	$log->lwrite("main:: FATAL, exiting now\n");
	exit(1);
}
else if ($debug>0) $log->lwrite("main:: Loaded the database, err: ".$apperr);

$devices     = $config['devices'];
$scenes      = $config['scenes'];
$timers      = $config['timers'];			// These will be refreshed IN the loop to get up-to-date timers
$rooms       = $config['rooms'];
$handsets    = $config['handsets'];
$brands      = $config['brands'];
$settings    = $config['settings'];
$controllers = $config['controllers'];
$weather     = $config['weather'];

$time_last_run = time();					// Last time we checked the queue. Initial to current time

// Loop forever, daemon like behaviour. For testing, we will use echo commands
// once the daemon is running operational, we should only echo to a logfile.

$log->lwrite("Sunrise on : ".date('H:i:s',date_sunrise(time(), SUNFUNCS_RET_TIMESTAMP, 52.13, 5.58, 90+50/60, 1)));
$log->lwrite("Sunset on : ".date('H:i:s',date_sunset(time(), SUNFUNCS_RET_TIMESTAMP, 52.13, 5.58, 90+50/60, 1)));

while (true):
							
// three scenarios/phases during the loop:
//
//
// 1. Read and parse the socket for for SCENE commands.
//		Run device commands immediately (set timeout or so), and add scene commands to the queue
//		(timer commands do not go through this interface but through MySQL directly)
// 2. Once a scene sequence is fired, the individual commands in the sequence might be timed later 
// 		(We can read when the next action in the QUEUE needs to fire, since QUEUE is stored on timestamp)
// 3. Timers from scenes stored in MySQL will fire. This needs to run every minute or so
//		but not more, since the timers have a one minute resolution in the database
// 
//		This is why timers and scenes live in same space in ICS-1000. Scenes are
//		just timers without start time

	$log->lwrite("------------------ Loop ------------------------",1);

	$tim = time();				// Get seconds since 1970. Timestamp
	$qtim = $queue->q_tim();
	
	//
	// Determine whether and how long we can wait, waiting on the socket uses less cpu cycles..... 
	//	- Until max the Queue time for next option. sleep($qtim - $tim) seconds.
	//  - Until max the next minute to the timer objects (scan every minute as in $interval )
	// 		sleep($interval - ($ntim-$tim) - 1);
	//  - If during waiting data arrives at the socket we will process, and then sleep again here...
	//
	// Calculate wait time for the socket:
	// Specify a waittime in the socket which is (1) less than $interval && (2) less than $qtim-$tim.
	// So that we wait no longer than the start time of next queue event...
	
	if ($debug > 2) $log->lwrite("tim: ".$tim.", qtim: ".$qtim);
	
	if ($qtim > 0) {
		if ( $sock->s_wait( min($interval,($qtim-$tim)) ) == -1) {
			$log->lwrite("Failure to set waittime on socket: ");
		}
	}
	else {
	// If there is nothing on the queue, items only are inserted over sockets or over the timer,
	// run every $interval second.
		if ( $sock->s_wait( $interval ) === -1) {
			if (debug>=1) $log->lwrite("main:: Failure to set wait time on socket: ");
		}
	}
	
	// -------------------------------
	// 1. STAGE 1 - LISTEN TO THE SOCKET LAYER FOR INCOMING MESSAGES
	// Lets look to the socket layer and see whether there are messages for use
	// and handle these messages. We will put actions in a QUEUE based on timestamp.
	
	while ( ($buf = $sock->s_recv() ) != -1 )
	{
		// The data structure read is decoded into a human readible string. jSon or raw
		//
		$log->lwrite("main:: s_recv returned Json with ".count($buf)." elements",3 );
		
		// Once we receive first message, read for more messages later, but without!! a timeout
		if ( $sock->s_wait(0) == -1) {
			$log->lwrite("ERROR main:: Failure to set wait (time=0) on socket: ");
		}
		
		// Make sure that if two json messages are present in the buffer
		// that we decode both of them..
		$i = 0;
		while (($pos = strpos($buf,"}",$i)) != FALSE )
		{
			$log->lwrite("s_recv:: ".substr($buf,$i,($pos+1-$i)) );
			
			$data = json_decode(substr($buf,$i,($pos+1-$i)), true);
			$tcnt = $data['tcnt'];						// Must be present in every communication
			
			if ($data == null) {
				switch (json_last_error()) {
							case JSON_ERROR_NONE:
            					$log->lwrite(" - No errors");
        					break;
        					case JSON_ERROR_DEPTH:
        					    $log->lwrite(" - Maximum stack depth exceeded");
       						break;
       						case JSON_ERROR_STATE_MISMATCH:
            					$log->lwrite(" - Underflow or the modes mismatch");
        					break;
        					case JSON_ERROR_CTRL_CHAR:
            					$log->lwrite(" - Unexpected control character found");
        					break;
        					case JSON_ERROR_SYNTAX:
            					$log->lwrite(" - Syntax error, malformed JSON");
        					break;
        					case JSON_ERROR_UTF8:
            					$log->lwrite(" - Malformed UTF-8 characters, possibly incorrectly encoded");
        					break;
        					default:
            					$log->lwrite(" - Unknown error");
        					break;
				}
			}

			// Print the fields in the jSon message
			if ($debug>=3) {
				$msg = "";
				foreach ($data as $key => $value) {
					$msg .= " ".$key."->".$value.", ";
				}
				$log->lwrite("main:: Rcv json msg: <".$msg.">");
			}
			
			// Check if this is a trusted Internal IP connection. Every address inside our home network
			// is trusted. Trustlevel needs to be larger than 0 to pass.
			// If we receive a message and our trustlevel is too low, we will not continue until
			// the user first "repairs" that trust. As a result, the last command from the client
			// will probably be lost, as all communication is async ...
			//
			if ( $sock->s_trusted() <= 0 ) 
			{
				// Here is when we do not trust the client
				// It could be however that the client just sent his login data, therefore
				// we check for these first
				//
				$log->lwrite("main:: external client ".$sock->clientIP." not trusted",3);
				
				// If user has aleady set local storage/cookie for this IP for the password, 
				// he/she will be done quickly
				//
				if ($data['action'] == "login" ){
					$log->lwrite("main:: received login request from ip ".$sock->clientIP.
							", action: ".$data['action'].", login: ".$data['login'].", password: ".$data['password'],2);
					if (User::pwcheck($data) > 0)
					{
							$akey = array_keys($sock->clients, $sock->ssock);
							$ckey = $akey[0];
							$sock->sockadmin[$ckey]['trusted'] = "1" ;
							$sock->sockadmin[$ckey]['login'] = $data['login'];
							$log->lwrite("main:: Password Correct, user: ".$sock->sockadmin[$ckey]['login']." @ IP: ".$sock->sockadmin[$ckey]['ip'],2);
							$i = $pos+1;
							if ($pos >= strlen($buf)) break;
							continue;
					}
					else
					{
						$log->lwrite("main:: Incorrect Password for user: ".$data['login']." IP: ".$sock->sockadmin[$ckey]['ip'],1);
						// So what are we going to do when we receive a wrong password
					}
				}
				
				// If we do NOT have a cookie, we need to use a login form 
				// cause only if we connect from remote, we'll need to login first
				// This is async, we only write the request, the answer will arrive in time
				// 
				$logmsg = array (
							'tcnt' => $tcnt."",
							'action' => 'login',
							'type' => 'raw'
				);
				if ( false === ($message = json_encode($logmsg)) ) {
					$log->lwrite("ERROR main:: json_encode failed: <".$logmsg['tcnt'].", ".$logmsg['action'].">");
				}
				$log->lwrite("Json encoded login: ".$message,2);
				// $answer = $sock->s_encode($message);
				if ( $sock->s_send($message) == -1) {
					$log->lwrite("ERROR main:: failed writing login message on socket");
				}
				$log->lwrite("main:: writing message on socket OK",2);
			}
			
			// Else we trust the client
			else {
				$log->lwrite("main:: client is trusted: ".$sock->clientIP,2);
			
				// Compose ACK reply for the client that sent us this message.
				// At this moment we use the raw message format in message ...
				
				$reply = array (
					'tcnt' => $tcnt."",
					'type' => 'raw',
					'action' => "ack",
					'message' => "OK"
				);
				if ( false === ($message = json_encode($reply)) ) {
					$log->lwrite("ERROR main:: json_encode reply: <".$reply['tcnt'].",".$reply['action'].">",1);
				} 
				// First check whether this is necessary. Some raw sockets are not websockets encoded
				// XXX $answer = $sock->s_encode($tmp);			// Websocket encode
				$log->lwrite("main:: json reply : <".$message."> len: ".strlen($message),2);

				// Send the reply	
				if ( $sock->s_send($message) == -1) {
					$log->lwrite("ERROR main:: failed writing answer on socket");
				}
				//
				// Take action on the message based on the action field of the message
				//
				switch ($data['action']) 
				{
				case "ping":
					$log->lwrite("main:: PING received",1);
				break;
				
				case "gui":
					// GUI message, probably in ICS coding
					// For compatibility with raw message format, we just use ICS format
					// NOTE that we have full json support implemented in LamPI-x.y.js, but NOT tested
					$cmd = $data['message'];
					message_parse($cmd);
				break;
				
				case "handset":
					// For compatibility with raw message format, we just use ICS format
					// to encode all content in a message field.
					$cmd = $data['message'];
					message_parse($cmd);
				break;
				
				case "weather":
					// Weather station message recognized.
					// Write the received values to the logfile, and read all fields.
					$wlog->lwrite("address: ". 
									 $data['address'].
									 ", channel: ". $data['channel'].			   
									 ", temperature: ". $data['temperature'].
									 ", humidity: ".  $data['humidity'] 
									 );
					
					// Send something to the client GUI?
					$item = array (
						'secs'  => time(),					// Set execution time to now or asap
						'tcnt' => $tcnt."",					// Transaction count
						'type' => 'json',					// We want a json message & json encoded values.
						'action' => 'weather',
						'brand' => $data['brand'],
						'address' => $data['address'],
						'channel' => $data['channel'],
						'temperature' => $data['temperature'],
						'humidity' => $data['humidity'],
						'windspeed' => $data['windspeed'],
						'winddirection' => $data['winddirection'],
						'rainfall' => $data['rainfall']
					);

					$wthr->upd($item);

					// If we push this message on the Queue with time==0, it will
					// be executed in phase 2
					$log->lwrite("main:: q_insert action: ".$item['action'].", temp: ".$item['temperature'],2);
					$queue->q_insert($item);
				break;

				case "energy":
					// Energy cation message received
					// XXX tbd
					$log->lwrite("main:: action: ".$item['action'],1);
				break;

				case "sensor":
					// Received a message from a sensor
					// XXX tbd
					$log->lwrite("main:: action: ".$item['action'],1);
				break;

				case "login":
					// Received a message for login. As the server will initiate this request
					// and as we do the client is still untrusted, this will probably never happen.
					// Could be used to increase trustlevel from level 1 to level 2
					// 
					$log->lwrite("main:: login request: ".$data['login'].", password".$data['password'],1);
				break;

				case "console":
					// Handling of interfaces. Fields:
					// action=="console", request=="clients","logs",
					$log->lwrite("main:: Received console message: ".$data['action'].", request: ".$data['request']);
					
					$list = console_message($data['request']);
					
					$response = array (
							'tcnt' => $tcnt."",
							'type' => 'raw',
							'action' => 'console',
							'request' => $data['request'],
							'response' => $list
					);
					if ( false === ($message = json_encode($response)) ) {
						$log->lwrite("ERROR main:: json_encode failed: <"
									 .$response['tcnt'].", ".$response['action'].">");
					}
					$log->lwrite("Json encoded console: ".$response['response'],2);
					
					if ( $sock->s_send($message) == -1) {
						$log->lwrite("ERROR main:: failed writing login message on socket");
						continue;
					}
					$log->lwrite("main:: writing console message on socket OK",2);
				break;

				default:
					$log->lwrite("ERROR main:: json data type: <".$data['type']
									."> not found using raw message");
					$cmd = $data['message'];
				}
			}
			
			// Advance the index in current buffer (multiple messages may be possible in
			// one buffer) but also messgae might be split over several buffers...
			//
			$i = $pos+1;
			if ($pos >= strlen($buf)) break;
		}// while !end of encoded string read
		
		// test for empty message
		if (strlen($data) == 0) 
		{
			$log->lwrite("main:: s_recv returned empty data object",3);
			break;
		}
		// normal raw socket, no websocket but use json to encode the response
		// But json encode in ICS format
		//
		else 
		{
			if ($debug>=1) $log->lwrite("ERROR main:: Rcv raw data cmd on rawsocket: <".$data.">");
			list ($tcnt, $cmd) = explode(',' , $data);
			// 
			// Messages start with a tcnt number, then a command, Then an argument all comma separated
			switch ($cmd) {
				case "PING":
					$log->lwrite("main:: PING received",2);
				break;
				
				default:
					$log->lwrite("main:: Unknown command received: ".$cmd,2);
					continue;
				break;
			}

			$reply = array (
				'tcnt' => $tcnt."",
				'action' => "ack",
				'type' => 'raw',
				'message' => "OK"
				);
			if ( false === ($answer = json_encode($reply)) ) {
				$log->lwrite("ERROR main:: json_encode failed: <".$reply['tcnt'].",".$reply['action'].">");
			}
		
			// Reply to the client with the transaction number just received
			if ( $sock->s_send($answer) == -1) {
				$log->lwrite("ERROR main:: failed writing reply on socket, tcnt: ".$tcnt);
			}
			$log->lwrite("main:: success writing reply on socket. tcnt: ".$tcnt,3);
		
			// Actually, although we might expect more messages we should also
			// be able to "glue" 2 buffers together if the incoming message is split by TCP/IP
			// message_parse parses the $cmd string and will push the commands
			// to the queue.
			$log->lwrite("main:: raw cmd to parse: ".$cmd,2);
			message_parse($cmd);
		}
	}// while not EOF s_recv


	// --------------------------------------------------------------------------------
	// 2. STAGE 2 RUN THE READY QUEUE OF COMMANDS
	//	If there is a queue of scene device commands, for example in a timer that have delayed execution,
	//	we need to keep track of those actions until all of them are started.
	// If none need starting, we can use the start-time of the next item in the QUEUE to calculate
	// the max waiting time for listening on the socket before waking-up...
	

	$tim = time();				// Get seconds since 1970. Timestamp. Makes sure that current time 
								// is at least as big as the time recorded for the queued items.
	
	if ($debug >= 3) {
		$log->lwrite("main:: printing and handling queue");
		$queue->q_print();
	}
	
	// XXX MMM QQQ Should index not be $j instead of $i?
	for ($j=0; $j<count($queue); $j++) {
		// Queue records contain scene name, timers (in secs) and commands (ready for kaku_cmd)
		// New records are put to the end of the queue, with timer being the secs to wait from initialization
		if ($debug > 1) $log->lwrite("main:: Handling queue, timestamp : ".date('[d/M/Y:H:i:s]',$tim),2);
		
		$items = $queue->q_pop();
		
		// We now have an array of items (commands in the scene). However commands may be complex (all out)...
		// Also, the queue can store any kind of message, be it raw or a jsons array so we have
		// to find out what we have here
		
		for ($i=0; $i< count($items); $i++) 
		{
			// For every item ...
			// Do we have the latest list of devices??
			// run-a-command-to-get-the-latest-list-of-devices;;
			
			// What to do if the command is all off. We have to expand the command to include all
			// the devices currently in the room. Since all off might be a popular command in scene and timer
			// commands we need to ensure correct timing. Because if the daemon runs with old data
			// we might miss a switch off of a devices that were added later to the scene.
			
			// Make use of the feature that the condition in the loop is re-evaluated for every iteration. SO
			// "explode" the ALL-OFF command, and replace it with the individual device commands, en put them at
			// the back of the queue-list being executed
			switch($items[$i]['action'])
			{
				case "weather":
					$log->lwrite("main:: Recognized WEATHER Message",3);
					$bcst = array (	
						// First part of the record specifies this message type and characteristics
						'tcnt' => "0",
						'action' => "weather",				// code for weather
						'type'   => "json",					// type either raw or json, we code content here too. 
						// Remainder of record specifies device parameters
						'brand'  => $items[$i]['brand'],
						'address'  => $items[$i]['address'],
						'channel'  => $items[$i]['channel'],
						'temperature'  => $items[$i]['temperature'],
						'humidity'  => $items[$i]['humidity'],
						'windspeed'  => $items[$i]['windspeed'],
						'winddirection' => $items[$i]['winddirection']
					);
					if ( false === ($answer = json_encode($bcst)) ) {
						$log->lwrite("main:: error weather broadcast encode: <".$bcst['tcnt']
									.",".$bcst['action'].">");
					}
					$sock->s_bcast($answer);
					//continue;
				break;
				
				case "gui":
					if ($debug>1) {
							$log->lwrite("main:: q_pop: ".$items[$i]['secs'].", scene: ".$items[$i]['scene']
							.", cmd: ".$items[$i]['cmd']);
					}
					$log->lwrite("main:: Recognized GUI message",2);
					$cmd = "";
					if (substr($items[$i]['cmd'],-2,2) == "Fa") {
						list( $room, $value ) = sscanf ($items[$i]['cmd'], "!R%dF%s" );
						for ($j=0; $j<count($devices);$j++) {
							if ($devices[$j]['room']==$room) {
								// add to the items array 
								$item = array(
    							'scene' => $items[$i]['scene'],
								'action' => $items[$i]['action'],
    							'cmd'   => "!R".$room . $devices[$j]['id']."F0",
								'secs'  => $items[$i]['secs']
   				 				);
								$items[] = $item;					// Add this item to end of array
							}
						}
						continue;									// End this iteration of the loop
					}
					// If not an ALL OFF command Fa, this is probably a normal device command
					// of form !RxDyFz or !RxDyFdPz (dimmer)
					else {
						$log->lwrite("main:: Action: time: ".$items[$i]['secs']
									.", scene: ".$items[$i]['scene'].", cmd: ".$items[$i]['cmd'],2);
						$cmd = $items[$i]['cmd'];
					}
					
					// If we have all devices, $devices contains list of devices
					// It is possible to look the device up through room and device combination!!
					list( $room, $dev, $value ) = sscanf ($items[$i]['cmd'], "!R%dD%dF%s\n" );
					$log->lwrite("room: ".$room." ,device: ".$dev." value: ".$value,2);
			
					$device = $dlist->get($room, $dev);

					// For which room, device is it, and what is the action?
					if (substr($value, 0, 2) == "dP" ) {
						$value = substr($value, 2);
						$device['val'] = $value;
						$device['lastval'] = $value;
						$sndval = $value;
					} 
					
					// Must be a switch turned on
					else if ($value == '1') { 						// in case F1
						if ($device['type'] == "switch") $device['val']='1';
						else $device['val']=$device['lastval'];
						$sndval = "on";
					} 
					
					// Must be a switch turned off
					else { 
						$device['val']= "0"; 						// F0
						$sndval="off";
					}
					
					$log->lwrite("sql device upd: ".$device['name'].", id: "
							.$device['id'].", room: ".$device['room'].", val: ".$device['val'],2);
	
					$brand = $brands[$device['brand']]['fname'];	// if is index for array (so be careful)
					$dlist->upd($device);							// Write new value to database
			
					$bcst = array (							// build broadcast message
						// First part of the record specifies this message type and characteristics
						'tcnt' => "0",
						'action' => "upd",					// code for remote command. upd tells we update a value
						'type'   => "raw",					// type either raw or json. 
						// Remainder of record specifies device parameters
						'gaddr'  => $device['gaddr'],
						'uaddr'  => $dev."",				// From the sscanf command above, cast to string
						'brand'  => $brand,					// NOTE brand is a string, not an id here
						'val'    => $sndval,				// Value is "on", "off", or a number (dimvalue) 1-32
						'message' => $items[$i]['cmd']		// The GUI message, ICS encoded 
					);
					if ( false === ($answer = json_encode($bcst)) ) {
						$log->lwrite("ERROR main:: broadcast encode: <".$bcst['tcnt']
									.",".$bcst['action'].">");
					}
					
					// XXX It is difficult to determine whether we should
					if ($brand == "zwave") {
						// For zwave we use a different protocol. Sending to transmitter.c does not help, we
						// will then  have several Raspberries react. We only need the dedicated Razberry device to
						// act on this command.
						zwave_send($bcst);					// We use $bcast as it is available already
					}
					
					$sock->s_bcast($answer);				// broadcast this command back to connected clients
					// XXX We need to define a json message format that is easier on the client.
					
					// Actually, broadcasting to all clients could include
					// broadcasting to the LamPI-receiver process where we can read the command
					// and call the correct handler directly.
				break;
				// QQQ
				default:
					$log->lwrite("main:: NO DEFINED ACTION: ".$items[$i]['action']);
			}//switch	

		}//for i
		
	}//for j
	if ($debug >= 3) {
		$log->lwrite("main:: queue finished ");
		$queue->q_print();
	}
	
	
	
	// -----------------------------------------------------------------------
	// 3. STAGE 3, RUN TIMERS FROM MYSQL
	// Process timers scenes in MySQL and see whether they need activation... 
	// Other processing based on content of timers in MySQL?
	// This part only needs to run once every 60 seconds or so, since the timer resolution in in MINUTES!
	// What influences the timing are sensors of weather stations, but that is compensated for ..
	
	$log->lwrite("main:: Entering the SQL Timers section",3);
	$timers = load_timers();					// This is a call to MSQL	
	$tim = time();
	// mktime(hour,minute,second,month,day,year,is_dst) NOTE is_dst daylight saving time
	// For EVERY object in the timer array, look whether we should take action
	//
	for ($i=0; $i < count($timers); $i++)
	{
		$log->lwrite("index: $i, id: ".$timers[$i]['id'].", name: ".$timers[$i]['name'],3);
		//
		list ( $start_hour, $start_minute) = sscanf($timers[$i]['tstart'], "%2d:%2d" );
		list ( $start_day, $start_month, $start_year ) = sscanf($timers[$i]['startd'], "%2d/%2d/%2d" );
		list ( $end_day, $end_month, $end_year ) = sscanf($timers[$i]['endd'], "%2d/%2d/%2d" );
		
		$start_second = "00";
		
		// The timing codes received for dusk/dawn are same as defined for the ICS-1000
		// Are the start times dawn (=twillight before sunrise) and dusk (=after Sunset)
		// The minutes are multiplied by 30 minutes
		// Apeldoorn 52� 13' 0" N / 5� 58' 0" E
		
		if (    $start_hour == "96") 		// 96:: dawn - m * 30
			{ $secs_today = date_sunrise(time(), SUNFUNCS_RET_TIMESTAMP, 52.13, 5.58, 90+50/60, 1)-($start_minute*30*60); }		
		else if($start_hour == "97") 		// 97:: dawn + m * 30 
			{ $secs_today = date_sunrise(time(), SUNFUNCS_RET_TIMESTAMP, 52.13, 5.58, 90+50/60, 1)+($start_minute*30*60); }
		else if($start_hour == "98") 		// 98: dusk - m * 30
			{ $secs_today = date_sunset(time(), SUNFUNCS_RET_TIMESTAMP, 52.13, 5.58, 90+50/60, 1)-($start_minute*30*60); }
		else if($start_hour == "99") 		// 99: dusk + m * 30
			{ $secs_today = date_sunset(time(), SUNFUNCS_RET_TIMESTAMP, 52.13, 5.58, 90+50/60, 1)+($start_minute*30*60); }
		else
			{ $secs_today = mktime($start_hour,$start_minute,$start_second); }
			
		// echo ("Start: ".$start_day." ".$start_month." ".$start_year."\n");
		///echo ("Time: ".$start_hour." ".$start_minute." ".$start_second."\n");
		///echo ("End  : ".$end_day  ." ".$end_month  ." ".$end_year."\n");
		
		// Start action if time interval between now - timer < 0
		
		$secs_start = mktime($start_hour,$start_minute,$start_second,$start_month,$start_day,$start_year);
		$secs_stop  = mktime($start_hour,$start_minute,$start_second,$end_month,$end_day,$end_year);
		
		// First check whether time should run this month and on this weekday!
		// The is a string of 'days' and 'months' that contain either characters of the month or
		// an "x" for blackout of a certain day of month.
		// $time = @date('[d/M/Y:H:i:s]');
		$months = $timers[$i]['months'];
		$days   = $timers[$i]['days'];
		
		if ($debug > 2) $log->lwrite ("DEBUG MONTH : ".$i." ".substr($months,@date('n')-1,1) );
		if ($debug > 2) $log->lwrite ("DEBUG DAY :   ".$i." ".substr($days,  @date('N')-1,1) );
		
		// Look of we have to skip this execution because either month, day of week or
		// cancel once is active. If so, we write to log and do not further execute this timer
		
		if (substr($timers[$i]['months'],@date('n')-1,1) == "x" ) {
			$log->lwrite ("This month is blocked from timer execution",1);
		}
		else
		if ( substr($timers[$i]['days'],@date('N')-1,1) == "x" ) {
			$log->lwrite ("main:: Today is blocked from timer execution",1);
		}
		
		// If the stoptime has passed, we do not have to do anything.
		// Only if some long-lasting programs would still be running in the background on the queue(naah)
		else
		if ( $tim > $secs_stop ) {
			if ($time_last_run > $secs_stop) {
				// $log->lwrite("Timer has possible already stopped, so we should not proceed any further");
			}
			else {
				$log->lwrite ("TIMER STOPPED: ".$timers[$i]['name']);	// probably stopped last time
			}
		}
		
		// If current time passes the start time, know that the command MIGHT be running
		// The timer is still 'LIVE'
		else 
		if (($tim > $secs_start) && ($tim > $secs_today)){
				
			if ($time_last_run > $secs_today ) {
				// We have already started at least one loop before
				// $log->lwrite("Timer ". $timers[$i]['name']." has been started already\n");
			}
			// Need to make sure ONLY when time > timer sttime
			// Need to push skip value back to database so next time we tun again !!!
			else if ( $timers[$i]['skip'] == "1" ) {
				$log->lwrite ("main:: Cancel Once execution was active",1);
				$timers[$i]['skip'] = "0";
				store_timer($timers[$i]);
			}
			else {
				// make the command and queue it (in step 1)
				$log->lwrite("STARTING TIMER ".$timers[$i]['name'].", scene: ".$timers[$i]['scene']." at ".date('Y-m-d', $tim));
				$scene = load_scene($timers[$i]['scene']);
				if ($scene == -1) { 								// Command not found in database
					$log->lwrite("main:: Cannot find scene: ".$timers[$i]['scene']." in the database");
					break; 
				}
				$scene_name = $scene['name'];
				$scene_seq = $scene['seq'];
				if ($scene_seq == "") {
					$log->lwrite("-- Scene: ".$scene['name']." found, but sequence empty (must save first)");
					//break;
				}
				$log->lwrite("-- Scene found, reading sequence: ".$scene_seq);
				$splits = explode(',' , $scene_seq);
				for ($i=0; $i< count($splits); $i+=2) {
					if ($debug > 0) $log->lwrite("cmd  : " . $i . "=>" . $splits[$i]);
					if ($debug > 0) $log->lwrite("timer: " . $i . "=>" . $splits[$i+1]);
				
					list ( $hrs, $mins, $secs ) = explode(':' , $splits[$i+1]);
					$log->lwrite("Wait for $hrs hours, $mins minutes and $secs seconds");
				
					// sleep(($hrs*3600)+($mins*60)+$secs);
					// We cannot sleep in the background now, it will give a timeout!!
					// we'll have tom implement something like a timeer through cron or so
					$item = array(
    					'scene' => $scene_name,
						'action' => 'gui',
    					'cmd'   => $splits[$i],
						'secs'  => ($hrs*3600)+($mins*60)+$secs+ $tim
   				 	);
					$queue->q_insert($item);
					if ($debug>1) $queue->q_print();
				}//for
			}//if
		}//if
	}//for
	$time_last_run = $tim;
	
endwhile;// ========= END OF LOOP ==========
 
// close log file
$log->lclose();
?>