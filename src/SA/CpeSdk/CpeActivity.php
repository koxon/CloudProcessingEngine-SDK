<?php

/**
 * This Class must be used to create your own activities.
 * Extend it in your own activities and implement the do_activity method
 */

namespace SA\CpeSdk;

use SA\CpeSdk;

class CpeActivity
{
    public $params;          // Activity params coming from ActivityPoller
    public $debug;           // Debug flag
    
    public $activityId;      // ID of the activity
    public $activityType;    // Type of activity
    
    public $cpeLogger;       // Logger
    public $cpeSqsWriter;    // Used to write messages in SQS
    public $cpeSwfHandler;   // USed to control SWF
    
    public $time;            // Time of the activity. Comes from $input
    public $data;            // Data input for the activity. The job we got to do. Comes from $input
    public $client;          // The client that request this activity. Comes from $input
    public $jobId;           // The Activity ID. Comes from $input
    
    public $input_str;       // Complete activity input string
    public $input;           // Complete activity input JSON object
    public $activityLogKey;  // Create a key workflowId:activityId to put in logs
    
    public function __construct($params, $debug)
    {
        $this->debug         = $debug;
        // For listening to the Input SQS queue
        $this->cpeSqsWriter  = new CpeSdk\Sqs\CpeSqsWriter($this->debug);
        // For listening to the Input SQS queue 
        $this->cpeSwfHandler = new CpeSdk\Swf\CpeSwfHandler();            
        // Save activity params
        $this->params        = $params;

        // Check if there is an activity name
        if (!isset($params["name"]) || !$params["name"])
            throw new CpeSdk\CpeException("Can't instantiate BasicActivity: 'name' is not provided or empty !\n", 
			    Self::NO_ACTIVITY_NAME);

        // Create logger object. Use activity name for logger
        $this->cpeLogger = new CpeSdk\CpeLogger(null, $params["name"]); 

        // Check if there is a version name
        if (!isset($params["version"]) || !$params["version"])
            throw new CpeSdk\CpeException("Can't instantiate BasicActivity: 'version' is not provided or empty !\n", 
			    Self::NO_ACTIVITY_VERSION);
    }

    /**
     * We perform basic high level task verifications
     * WE make sure it contains an input and we save it
     * This is the first method to be called when your activity starts
     */
    public function do_task_check($task)
    {
        if (!$task)
            throw new CpeSdk\CpeException("Activity Task empty !", 
			    self::ACTIVITY_TASK_EMPTY); 
        
        if (!isset($task["input"]) || !$task["input"] ||
            $task["input"] == "")
            throw new CpeSdk\CpeException("No input provided to 'Activity'", 
			    self::NO_INPUT);

        // Save input string
        $this->input_str      = $task["input"];

        // Save Task info
        $this->activityId     = $task->get("activityId");
        $this->activityType   = $task->get("activityType");
        
        // Create a key workflowId:activityId to put in logs
        $this->activityLogKey =
            $task->get("workflowExecution")['workflowId'] 
            . ":$this->activityId";
    }

    /**
     * We initialise the Activity in SWF
     * WE check if it is already registered or not
     * If not we register it
     */
    public function do_init()
    {
        // Save activity info
        $this->activityType = array(
            "name"    => $this->params["name"],
            "version" => $this->params["version"]);

        try {
            // Check if activity already exists 
            $this->cpeSwfHandler->swf->describeActivityType(array(
                    "domain"       => $this->params["domain"],
                    "activityType" => $this->activityType
                ));
            
            // Activity exists as there is no exception
            return true;
        } catch (\Aws\Swf\Exception\UnknownResourceException $e) {
            $this->cpeLogger->log_out("ERROR", basename(__FILE__), 
                "Activity '" . $this->params["name"] . "' doesn't exists. Creating it ...\n");
        }
        
        // Register activites if doesn't exists in SWF
        $this->cpeSwfHandler->swf->registerActivityType($this->params);
    }

    /**
     * Perform JSON input validation
     * We capture the four keys that compose a CPE Task input
     */
    public function do_input_validation()
    {
        // Check JSON input
        if (!($this->input = json_decode($this->input_str)))
            throw new CpeSdk\CpeException("JSON input is invalid !", 
			    self::INPUT_INVALID);
        
        $this->time   = $this->input->{'time'};  
        $this->jobId  = $this->input->{'job_id'};         
        $this->data   = $this->input->{'data'};  
        $this->client = $this->input->{'client'};

        print "DATA: ".print_r($this->data, true);
    }
    
    /**
     * Send SQS notification saying your activity started
     * Implement this method in your activity
     * This is where your logic starts
     */ 
    public function do_activity($task)
    {
        // Send started through SQS to notify client
        $this->cpeSqsWriter->activity_started($task);
    }

    /**
     * Activity failed to SQS and SWF
     * Called by ActivityPoller if your activity throws an exeception 
     */
    public function activity_failed($task, $reason = "", $details = "")
    {
        try {
            // Notify client of failure
            $this->cpeSqsWriter->activity_failed($task, $reason, $details);
            
            $this->cpeLogger->log_out("ERROR", basename(__FILE__),
                "[$reason] $details",
                $this->activityLogKey);
            
            $this->cpeSwfHandler->swf->respondActivityTaskFailed(array(
                    "taskToken" => $task["taskToken"],
                    "reason"    => $reason,
                    "details"   => $details,
                ));
        } catch (\Exception $e) {
            $this->cpeLogger->log_out("ERROR", basename(__FILE__), 
                "Unable to send 'Task Failed' response ! " . $e->getMessage(),
                $this->activityLogKey);
            return false;
        }
    }

    /**
     * Send activity completed to SQS and SWF
     * Called by ActivityPoller once your activity completed
     */
    public function activity_completed($task, $result = null)
    {
        try {
            // Notify client of failure
            $this->cpeSqsWriter->activity_completed($task, $result);
        
            $this->cpeLogger->log_out("INFO", basename(__FILE__),
                "Notify SWF activity is completed !",
                $this->activityLogKey);
            $this->cpeSwfHandler->swf->respondActivityTaskCompleted(array(
                    "taskToken" => $task["taskToken"],
                    "result"    => json_encode($result),
                ));
        } catch (\Exception $e) {
            $this->cpeLogger->log_out("ERROR", basename(__FILE__), 
                "Unable to send 'Task Completed' response ! " . $e->getMessage(),
                $this->activityLogKey);
            return false;
        }
    }
    
    /**
     * Send heartbeat to SWF to keep the task alive.
     * Timeout is configurable at the Activity level in SWF
     * You must call this regularly within your logic to make sure SWF doesn't
     * consider your activity as 'timeed out'.
     */
    public function send_heartbeat($task, $details = null)
    {
        try {
            $taskToken = $task->get("taskToken");
            $this->cpeLogger->log_out("INFO", basename(__FILE__), 
                "Sending heartbeat to SWF ...",
                $this->activityLogKey);
      
            $info = $this->cpeSwfHandler->swf->recordActivityTaskHeartbeat(array(
                    "details"   => $details,
                    "taskToken" => $taskToken));

            // Workflow returns if this task should be canceled
            if ($info->get("cancelRequested") == true)
            {
                $this->cpeLogger->log_out("WARNING", basename(__FILE__), 
                    "Cancel has been requested for this task '" . $task->get("activityId") . "' ! Killing task ...",
                    $this->activityLogKey);
                throw new CpeSdk\CpeException("Cancel request. No heartbeat, leaving!",
                    self::HEARTBEAT_FAILED);
            }
        } catch (\Exception $e) {
            throw new CpeSdk\CpeException("Heartbeat failed !: ".$e->getMessage(),
                self::HEARTBEAT_FAILED);
        }
    }
}
