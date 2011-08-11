<?php
/**
 * Modified from code by:
 * Oren Solomianik’s
 * http://orensol.com/2009/02/12/how-to-delete-those-old-ec2-ebs-snapshots/
 * New region, snapshots managed as incremental backups and no-op code from:
 * @author Erik Dasque
 * @version 0.8
 * @copyright Erik Dasque, 8 March, 2010
 * @package default
 * Revisited by Michele Marcucci
 * @version 0.1
 * @ 11 August 2011
 * WARNING : USE AT YOU OWN RISK!!! This application will delete snapshots unless you use the --noop option
 **/
 
	// Enable full-blown error reporting.
	error_reporting(-1);

	// Set HTML headers
	header("Content-type: text/html; charset=utf-8");

	// Include the SDK
	require_once './sdk.class.php';

	// Instantiate the AmazonEC2 class
	$ec2 = new AmazonEC2();
	
	$ec2->set_region(AmazonEC2::REGION_EU_W1);

	$volumes = listVolumes($ec2);
	$snapshots = listSnapshots($ec2);

	$docreate = true;
	$dodelete = true;

	// Create the snapshots
	foreach ( $volumes as $volume ) {
		
		if ( $volume['status'] == "in-use" OR $volume['status'] == "available" )
		{
			// Set snap counter to zero
			$count = 0;
		
			foreach ( $snapshots as $snapshot ) {

				if ( $snapshot['volumeId'] == $volume['volumeId'] && $snapshot['status'] == "pending" )
				{
					echo "Skipping snapshot for volume[".$volume['volumeId']."]\n";
					echo "There is one still in pending status snapshot[".$snapshot['snapshotId']."]\n\n";
				} 
				elseif ( $snapshot['volumeId'] == $volume['volumeId'] ) 
				{
					// Get this only one time, we don't want to create duplicated snap
					if ($count == 0)
					{
						echo "Ready to create snapshot for volume[".$volume['volumeId']."]\n";

						// and now really create it
						if ($docreate) 
						{
							$response = createSnapshot($ec2, $volume['volumeId'], $volume['instanceId'], $volume['device']);			
							echo "Status: " . $response . "\n\n";
						}
					}
	
					$count++;
				}
			}			
		}
	}
	
	// Delete old snapshots
	
	// first check we have at least 1 newer snapshot for every vol-id we got
	// we don't want to delete all snapshots of a vol and be left with no snapshots, 
	// this guarantees it. so we build a "go_ahead_volumes" array.
	$now = time();
	$older_than = $now - 7 * 24 * 60 * 60;

	foreach ( $volumes  as $volume ) {

		foreach ( $snapshots as $snapshot ) {
		
			$snapTimestamp = strtotime($snapshot['startTime']);
			$snapStatus = $snapshot['status'];
			
			if (($snapTimestamp >= $older_than) && ($snapStatus=="completed"))
			{
				if ($snapshot['volumeId'] == $volume['volumeId'])
				{
					$go_ahead_volumes[] = $volume['volumeId'];
					echo "Ready for deletion of snapshots older than ".date("Y/m/d H:i:s e", $older_than). " for volume[".$volume['volumeId']."]";
					echo ",\nfound newer snapshot [" . $snapshot['snapshotId'] . "] taken on " . date('Y/m/d \a\t H:i:s e',$snapTimestamp) .  "\n\n";
					break;
				}
			}
		}
	}
	
	if (empty($go_ahead_volumes))  die ("No snapshots found for these volumes\n\n");
	
	echo "\n";
	
	// now go over all snaps, if encounter a snap for a go_ahead_volume which
	// is older than, well, older_than, delete it.
	
	foreach ( $snapshots as $snapshot )
	{
		$snapTimestamp = strtotime($snapshot['startTime']);
		
		if ( (in_array($snapshot['volumeId'], $go_ahead_volumes)) )
		{		
			if (!keepSnapShot($snapshot['startTime'])) {			
				echo "Deleting volume " . $snapshot['volumeId'] . " snapshot " . $snapshot['snapshotId'] . " created on: " . date('Y/m/d \a\t H:i:s e',$snapTimestamp) ."\n";
				
				// and now really delete using EC2 library
				if ($dodelete) 
				{
					$response = $ec2->delete_snapshot($snapshot['snapshotId']);
					echo "Status: " . (string)$response->status . "\n\n";
				}
			}
			    
		}
	}
	echo "\n\n";



	function  keepSnapShot($creation_date)
	{
		$now = time();
		$older_than = $now - 7 * 24 * 60 * 60;
		$older_than_month = $now - 30 * 24 * 60 * 60;
		
		
		// echo strtotime($creation_date);
		$ts = strtotime($creation_date);
		
	//	echo 'Day of month: '.date("d",$ts)."\n";
	//	echo 'Day of week: '.date("w",$ts)."\n";
		
		echo date('M d, Y',$ts)."\t";
		
		if ($ts>=$older_than) { 
			echo "Recent backup\tKEEP\n" ;
			return(TRUE); 
			} 
		if (date("d",$ts)==1) { 
			echo "1st of month\tKEEP\n" ; 
			return(TRUE); 
			}
		if ((date("w",$ts)==0) && $ts>$older_than_month) { 
			echo "Recent Sunday\tKEEP\n" ;
			return(TRUE); 
			} 
		if ((date("w",$ts)==0) && $ts<=$older_than_month) { 
			echo "Old Sunday\tDELETE\n" ;
			return(FALSE); 
			} 
		if ($ts<$older_than) { 
			echo "Old backup\tDELETE\n" ; 
			return(FALSE); 
			} 
			
		
		echo "Unknown condition on ".date('F d, Y',$ts)."\n"; exit(0);
		return(FALSE); 
	}

	// -------------------------------------------
	//
	// Methods based on AWS API
	//
	// -------------------------------------------
	
	function createSnapshot($obj, $volumeId, $instanceId, $device) 
	{
		$instance = listInstances($obj, $instanceId);
		
		$response = $obj->create_snapshot($volumeId, Array( "Description" => "AutoSnap: " . $instance[0]['tagName'] . " - " . $device . " (" . $volumeId . ") " . date('Ymd - H:i:s', time()) ));
		
		return (string)$response->body->status;
	}
	
	function listVolumes($obj) 
	{
		$response = $obj->describe_volumes();
	
		foreach ( $response->body->volumeSet->item as $item ) {
			
			$volumeId = (string)$item->volumeId;

			$output[] = Array(
				"volumeId" => $volumeId, 
				"device" => (string)$item->attachmentSet->item->device, 
				"instanceId" => (string)$item->attachmentSet->item->instanceId,
				"status" => (string)$item->status
			);

		}
		
		return $output;
	}
	
	function listSnapshots($obj) 
	{
		$response = $obj->describe_snapshots();
	
		foreach ( $response->body->snapshotSet->item as $item ) {
			$output[] = Array(
				"snapshotId" => $item->snapshotId, 
				"volumeId" => $item->volumeId, 
				"status" => $item->status, 
				"startTime" => $item->startTime
			);
		}

		return $output;
	}
	
	function listInstances($obj, $instanceId = null) 
	{
		if (is_null($instanceId))
			$response = $obj->describe_instances();
		else
			$response = $obj->describe_instances(Array("InstanceId" => $instanceId));
	
		if ( $response->body->reservationSet->item )
		{
			foreach ($response->body->reservationSet->item as $instance) {
				$tagName = (string)$instance->instancesSet->item->tagSet->item->value;
				$instanceId = (string)$instance->instancesSet->item->instanceId;
				$blockDevices = $instance->instancesSet->item->blockDeviceMapping->item;
				
				foreach ( $blockDevices as $volume ) {
					if ( preg_match("/sda1/", $volume->deviceName) )
						$ebsVolumeId = (string)$volume->ebs->volumeId;
				}
				
				$output[] = array(
					"tagName" => $tagName, 
					"instanceId" => $instanceId, 
					"ebsVolumeId" => $ebsVolumeId
				);
			}
		}
		else
		{
			$output[] = array(
				"tagName" => "N.A."
			);
		}

		return $output;
	}

?>