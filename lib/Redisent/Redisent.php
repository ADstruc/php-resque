<?php
/**
 * Redisent, a Redis interface for the modest
 * @author Justin Poliey <jdp34@njit.edu>
 * @copyright 2009 Justin Poliey <jdp34@njit.edu>
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 * @package Redisent
 */

!defined('CRLF') && define('CRLF', sprintf('%s%s', chr(13), chr(10)));

if(!class_exists('RedisException')) {
  /**
   * Wraps native Redis errors in friendlier PHP exceptions
   */
  class RedisException extends Exception {
  }
}

if(!class_exists('Redisent')) {
    /**
     * Redisent, a Redis interface for the modest among us
     */
    class Redisent {

        /**
         * Socket connection to the Redis server
         * @var resource
         * @access private
         */
        private $__sock;
        
        /**
         * 
         * @var boolean
         * @access private 
         */
        private $__subscribed;
        
        /**
         *
         * @var array
         * @access private 
         */
        private $__subscriptions;

        /**
         * Host of the Redis server
         * @var string
         * @access public
         */
        public $host;

        /**
         * Port on which the Redis server is running
         * @var integer
         * @access public
         */
        public $port;

        /**
         * Creates a Redisent connection to the Redis server on host {@link $host} and port {@link $port}.
         * @param string $host The hostname of the Redis server
         * @param integer $port The port number of the Redis server
         */
        public function __construct($host, $port = 6379) {
            $this->host = $host;
            $this->port = $port;
            $this->__subscribed = false;
            $this->__subscriptions = array();
            
            $this->establishConnection();
        }

        protected function establishConnection() {
            $this->__sock = fsockopen($this->host, (integer) $this->port, $errno, $errstr);
            if (!$this->__sock) {
                throw new Exception("{$errno} - {$errstr}");
            }
        }

        public function __destruct() {
            fclose($this->__sock);
        }
        
        /**
         *
         * @param array $channels 
         */
        public function subscribe($channels = array()) {
            if($this->__subscribed) {
                throw new RedisException('Already subscribed to channels. You must unsubscribe first.');
            }
            
            if(!is_array($channels)) {
                $channels = array($channels);
            }
            
            $command = $this->buildCommand('subscribe', $channels);
            
            $this->writeCommand($command);
            
            $args = trim(fgets($this->__sock, 512));
            if(substr($args, 0, 1) !== '*') {
                throw new RedisException('Response was not a multi-bulk reply');
            }
            
            $count = (integer) substr($args, 1);
            if($count !== 3) {
                throw new RedisException("Multi-bulk reply returned $count arguments, expected 3.");
            }
            
            //@todo look into validating these responses
            $commandLen = trim(fgets($this->__sock));
            $command    = trim(fgets($this->__sock));
            $channelLen = trim(fgets($this->__sock));
            $channels   = trim(fgets($this->__sock));
            $success    = trim(fgets($this->__sock));
            
            $this->__subscribed = true;
            $this->__subscriptions = $channels;
        }
        
        /**
         *
         * @return array 
         */
        public function listenToSubscription() {
            if(!$this->__subscribed) {
                throw new RedisException("Must be subscribed to a channel before listening");
            }
            
            $response = array();
            
            $argsCount = (integer) substr(trim(fgets($this->__sock)), 1);
            if($argsCount !== 3) {
                throw new RedisException("Improper number of arguments returned. Got $argsCount, expected 3.");
            }
            
            for($i = 0; $i < 6; $i++) {
                $response[] = trim(fgets($this->__sock));
            }
            
            $channelLen = (integer) substr($response[2], 1);
            $channel    = substr($response[3], 0, $channelLen);
            $messageLen = (integer) substr($response[4], 1);
            $message    = substr($response[5], 0, $messageLen);
            
            
            return compact('channel', 'message');
        }
        
        /**
         *
         * @param array $channels 
         */
        public function unsubscribe($channels = array()) {
            if(!$this->__subscribed) {
                throw new RedisException('You must be subscribed to channels before unsubscribing');
            }
            
            if(!is_array($channels)) {
                $channels = array($channels);
            }
            
            $command = $this->buildCommand('unsubscribe', $channels);
            
            $this->__subscribed = false;
            $this->__subscriptions = array();
        }

        /**
         *
         * @param string $name
         * @param array $args
         * 
         * @return type 
         */
        public function __call($name, $args) {
            if($this->__subscribed) {
                throw new RedisException('Cannot execute other commands while subscribed');
            }
            
            /* Build the Redis unified protocol command */
            $command = $this->buildCommand($name, $args);

            /* Open a Redis connection and execute the command */
            $this->writeCommand($command);

            /* Parse the response based on the reply identifier */
            $reply = trim(fgets($this->__sock, 512));
            switch (substr($reply, 0, 1)) {
              /* Error reply */
              case '-':
                  throw new RedisException(substr(trim($reply), 4));
                  break;
              /* Inline reply */
              case '+':
                  $response = substr(trim($reply), 1);
                  break;
              /* Bulk reply */
              case '$':
                  $response = null;
                  if ($reply == '$-1') {
                      break;
                  }
                  $read = 0;
                  $size = substr($reply, 1);
                  do {
                      $block_size = ($size - $read) > 1024 ? 1024 : ($size - $read);
                      if ($block_size > 0) {
                        $response .= fread($this->__sock, $block_size);
                        $read += $block_size;
                      }
                  } while ($read < $size);
                  fread($this->__sock, 2); /* discard crlf */
                  break;
              /* Multi-bulk reply */
              case '*':
                  $count = substr($reply, 1);
                  if ($count == '-1') {
                      return null;
                  }
                  $response = array();
                  for ($i = 0; $i < $count; $i++) {
                      $bulk_head = trim(fgets($this->__sock, 512));
                      $size = substr($bulk_head, 1);
                      if ($size == '-1') {
                          $response[] = null;
                      }
                      else {
                          $read = 0;
                          $block = "";
                          do {
                              $block_size = ($size - $read) > 1024 ? 1024 : ($size - $read);
                              if ($block_size > 0) {
                                $block .= fread($this->__sock, $block_size);
                                $read += $block_size;
                              }
                          } while ($read < $size);
                          fread($this->__sock, 2); /* discard crlf */
                          $response[] = $block;
                      }
                  }
                  break;
              /* Integer reply */
              case ':':
                  $response = intval(substr(trim($reply), 1));
                  break;
              default:
                  throw new RedisException("invalid server response: {$reply}");
                  break;
            }
            /* Party on */
            return $response;
        }
        
        /**
         * Build the Redis unified protocol command
         * 
         * @param string $name
         * @param array $args
         * 
         * @return string 
         */
        private function buildCommand($name, $args = array()) {
            array_unshift($args, strtoupper($name));
            return sprintf('*%d%s%s%s', count($args), CRLF, implode(array_map(array($this, 'formatArgument'), $args), CRLF), CRLF);
        }
        
        /**
         *
         * @param string $command 
         * @exception \Exception
         */
        private function writeCommand($command) {
            for ($written = 0; $written < strlen($command); $written += $fwrite) {
                $fwrite = fwrite($this->__sock, substr($command, $written));
                if ($fwrite === FALSE) {
                    throw new Exception('Failed to write entire command to stream');
                }
            }
        }

        private function formatArgument($arg) {
            return sprintf('$%d%s%s', strlen($arg), CRLF, $arg);
        }
    }
}