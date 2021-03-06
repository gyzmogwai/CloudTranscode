<?php

require_once __DIR__ . '/transcoders/BasicTranscoder.php';

/**
 * This class handle the transcoding activity
 */
class TranscodeAssetActivity extends BasicActivity
{
    const CONVERSION_TYPE_ERROR = "CONVERSION_TYPE_ERROR";
    const TMP_PATH_OPEN_FAIL    = "TMP_PATH_OPEN_FAIL";
    const UNKOWN_OUTPUT_TYPE    = "UNKOWN_OUTPUT_TYPE";

    private $output;
    private $pathToOutputFiles;
    
    // Perform the activity
    public function do_activity($task)
    {
        // Check input task. Set $this->input_str String
        parent::do_task_check($task);
        
        // Init Activity
        parent::do_init($task);
        
        // Validate JSON. Set $this->input JSON object
        parent::do_input_validation(
            $task, 
            $this->activityType["name"]
        );
        
        // Custom validation for transcoding. Set $this->output
        $this->validate_input();
        
        log_out(
            "INFO", 
            basename(__FILE__), 
            "Preparing Asset transcoding ...",
            $this->activityLogKey
        );
        
        // Call parent method for initialization.
        // Setup TMP folder
        // Send starting SQS message
        // Download input file from S3
        parent::do_activity($task);

        // Set output path to store result files
        $this->set_output_path();
        
        
        /**
         * TRANSCODE INPUT FILE
         */
        
        switch ($this->output->{'output_type'}) 
        {
        case VIDEO:
        case THUMB:
            require_once __DIR__ . '/transcoders/VideoTranscoder.php';

            // Instanciate transcoder to output Videos
            $videoTranscoder = new VideoTranscoder($this, $task);
            
            // Check preset file, read its content and add its data to output object
            // Only for VIDEO output. THUMB don't use presets
            if ($this->output->{'output_type'} == VIDEO)
                $this->output->{'preset_values'} =
                $videoTranscoder->get_preset_values($this->output);
                
            // Perform transcoding
            $videoTranscoder->transcode_asset(
                $this->pathToInputFile,
                $this->pathToOutputFiles,
                $this->data->{'input_asset_info'}, 
                $this->output
            );            
            break;
        case IMAGE:
                
            break;
        case AUDIO:
                
            break;
        case DOC:
                
            break;
        default:
            throw new CTException("Unknown 'output_type'! Abording ...", 
                self::UNKOWN_OUTPUT_TYPE);
        }
        
        // Upload resulting file
        $this->upload_result_files($task);

        return null;
    }

    // Upload all output files to destination S3 bucket
    private function upload_result_files($task)
    {
        // Sanitize output bucket and file path "/"
        $s3Bucket   = str_replace("//", "/", $this->output->{"output_bucket"});

        // XXXXXXXXXXXXXXXXXXXXXXXXXXXXX
        // XXX: Add tmp workflowID to output bucket to seperate upload
        // XXX: For testing only !
        // $s3Bucket .= "/".$task["workflowExecution"]["workflowId"];
        // XXXXXXXXXXXXXXXXXXXXXXXXXXXXX

        // Prepare S3 options
        $options = array("rrs" => false, "encrypt" => false);
        if (isset($this->output->{'s3_rrs'}) &&
            $this->output->{'s3_rrs'} == true)
            $options['rrs'] = true;
        if (isset($this->output->{'s3_encrypt'}) &&
            $this->output->{'s3_encrypt'} == true)
            $options['encrypt'] = true;

        // S3 object to handle S3 put operations
        $s3Utils = new S3Utils();
        
        // Send all output files located in '$pathToOutputFiles' to S3 bucket
        if (!$handle = opendir($this->pathToOutputFiles))
            throw new CTException("Can't open tmp path '$this->pathToOutputFiles'!", 
                self::TMP_PATH_OPEN_FAIL);
        
        // Upload all resulting files sitting in same dir
        while ($entry = readdir($handle)) {
            if ($entry == "." || $entry == "..") 
                continue;

            // Destination path on S3. Sanitizing
            $s3Location = $this->output->{'output_file_info'}['dirname'] . "/$entry";
            $s3Location = str_replace("//", "/", $s3Location);
            
            // Send to S3
            $s3Output = $s3Utils->put_file_into_s3(
                $s3Bucket, 
                $s3Location,
                "$this->pathToOutputFiles/$entry", 
                $options, 
                array($this, "s3_put_processing_callback"), 
                $task
            );
        
            log_out("INFO", basename(__FILE__), 
                $s3Output['msg'],
                $this->activityLogKey);
        }
    }

    private function set_output_path()
    {
         // Create TMP folder for output files
        $outputFileInfo = pathinfo($this->output->{'output_file'});
        $this->output->{'output_file_info'} = $outputFileInfo;
        $this->pathToOutputFiles = $this->tmpPathInput . "/output/" 
            . $this->activityId
            . "/" . $outputFileInfo['dirname'];
        if (!file_exists($this->pathToOutputFiles))
            if (!mkdir($this->pathToOutputFiles, 0750, true))
                throw new CTException(
                    "Unable to create temporary folder '$this->pathToOutputFiles' !",
                    self::TMP_FOLDER_FAIL
                );
    }
    
    // Perform custom validation on JSON input
    // Callback function used in $this->do_input_validation
    private function validate_input()
    {
        $this->output = $this->data->{'output'};
        if ((
                $this->data->{'input_type'} == VIDEO &&
                $this->output->{'output_type'} != VIDEO &&
                $this->output->{'output_type'} != THUMB &&
                $this->output->{'output_type'} != AUDIO
            )
            ||
            (
                $this->data->{'input_type'} == IMAGE &&
                $this->output->{'output_type'} != IMAGE
            )
            ||
            (
                $this->data->{'input_type'} == AUDIO &&
                $this->output->{'output_type'} != AUDIO
            )
            ||
            (
                $this->data->{'input_type'} == DOC &&
                $this->output->{'output_type'} != DOC
            )) {
            throw new CTException("Can't convert that 'input_type' (" . $this->data->{'input_type'} . ") into this 'output_type' (" . $this->output->{'output_type'} . ")! Abording.", 
                self::CONVERSION_TYPE_ERROR);
        }
        
        // Specific tests for VIDEO
        if ($this->output->{'output_type'} == VIDEO)
        {
            require_once __DIR__ . '/transcoders/VideoTranscoder.php';
                
            // Initiate transcoder obj
            if (!isset($videoTranscoder))
                $videoTranscoder = new VideoTranscoder($this, $task);
            // Validate output preset
            $videoTranscoder->validate_preset($this->output);
        }
    }
}


