<?php
$root = "directory_path";
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

foreach ($rii as $file) {
    if ($file->isDir()){
        continue;
    }

	$file = $file->getPathname();
	if(strpos($file, ".DS_Store")) {
		continue;
	}
	else {
		echo "\n\n\n";		
		$newname = explode(".", $file)[0].".jpg";
		echo $cmd = "convert ".$file." -resize 500x500 -quality 85% ".$newname;
		echo "<br>";
		exec($cmd);
		usleep(400000);
	}
}
