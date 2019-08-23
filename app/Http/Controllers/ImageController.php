<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Aws\S3\S3Client;

class ImageController extends Controller
{
    private $storage;
    private $img;

    public function __construct()
    {
        $this->storage = new S3Client([
            'region'          => 'us-east-1',
            'version'         => 'latest',
            'credentials' => ['key' => env('S3ACCESSID'), 'secret' => env('S3SECRET')]
        ]);

        $this->img = "assets/default-wine-image.png";
    }

    /**
     * @return string
     */
    public function getCurrentImg()
    {
        return $this->img;
    }

    /**
     * @param $img
     */
    public function overrideDefaultImage($img)
    {
        $this->img = $img;
    }

    /**
     * Mostly used for testing...
     *
     * @return S3Client
     */
    public function getStorageAccessor()
    {
        return $this->storage;
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(Request $request)
    {
        //echo "at start";
        //echo "--";
        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            //echo "file is ".$file."--";
            $fileName = "imgManageUpload".time().".".$file->getClientOriginalExtension();
            //echo "filename is ".$fileName."--";
            $mime = $file->getMimeType();
            //echo "mime is ".$mime."--";
            $ttmpdir = env('TMP_DIR');
            //echo "tmpdir is ".$ttmpdir."--";

            $file->move(env('TMP_DIR'), $fileName);
            $path = env('TMP_DIR')."/".$fileName;
            //echo "path is ".$path."--";
            
            $hash = md5(file_get_contents($path));
            $cacheRet = Redis::get($hash);
            $saveFileName = "$hash.".$file->getClientOriginalExtension();
            //echo "savefilename is ".$saveFileName."--";


            if ($cacheRet != null) {
                unlink($path);
                return response()->json(['set' => $cacheRet, 'file' => $saveFileName], 200);
            }


            try {
                //echo "Just before putobject--";
                $result = $this->storage->putObject([
                    'ACL' => 'public-read',
                    'Bucket' => env('BUCKET'),
                    'ContentType' => $mime,
                    'Key' => $saveFileName,
                    'ServerSideEncryption' => 'AES256',
                    'SourceFile' => $path,
                    'StorageClass' => 'REDUCED_REDUNDANCY',
                ]);
                //echo "Just after putobject--";
                $etag = (string)trim($result['ETag'], '"');
                if ($etag != $hash) {
                    //Problem Pushing to S3 Most Likely
                    throw new \Exception("MD5/eTag Mismatch");
                }
            } catch (\Exception $e) {
                dd($e->getAwsErrorMessage());
                return response()->json(['error' => $e->getMessage()], 401);
            }


            $setDate = date('c');
            //echo "setdate is ".$setDate."--";
            Redis::set($hash, $setDate);
            return response()->json(['set' => $setDate, 'file' => $saveFileName], 200);
        }
    }

    /**
     * @param $id
     * @param int $height
     * @param int $width
     * @param int $timeout
     */
    private function display($id, $height = 0, $width = 0, $timeout = 0)
    {
        //echo "In function display, id=".$id." height=".$height." width=".$width." timeout=".$timeout."--";
        //dd("end");
        $image = new \Imagick();
        //dd("end");
        //echo "right after creating new Imagick image --";
        //dd("end");
        $image->readImage($this->img);
        //echo "img is ".$this->img." --";
        //dd("end");
        //$height = env('MAX_HEIGHT');
        //$width = env("MAX_WIDTH");

        //echo "before height/width check, height =".$height." width=".$width."--";
        if ($height > 0 && $width > 0) {
            if ($height > env("MAX_HEIGHT")) {
                $height = env('MAX_HEIGHT');
            }
            if ($width > env('MAX_WIDTH')) {
                $width = env("MAX_WIDTH");
            }
            //echo "height =".$height." width=".$width."--";
            $filter = \Imagick::FILTER_CUBIC;
            $image->resizeImage(
                $width,
                $height,
                $filter,
                0.5,
                true
            );
            //echo "right after resizeImage() --";
        }
        //echo "right after height/width check, height =".$height." width=".$width."--";
        //dd("at the end");

        ob_start();
        echo $image;
        ob_clean();
        //dd("at the end");
        $len = $image->getImageLength();
        $mime = $image->getImageMimeType();
        //echo "len=".$len." mime=".$mime."--";
        //dd("at the end");


        header('Content-type: '.$mime);
        header('Pragma: public');
        //$timeout = <timeout>;
        //header('Expires: ' . gmdate("D, d M Y H:i:s", time() + $timeout) . " GMT");
        //header("Cache-Control: public, max-age=$timeout");
        header('Content-Length: '.$len);
        
        echo $image;
        ob_end_flush();  //original code
        //flush();  // from video
        //dd("at the end");
        $image->destroy();
    }

    /**
     * @param $url
     * @return bool
     */
    private function validateImg($url)
    {
        try {
            $h = get_headers($url);
            if ($h[0] == 'HTTP/1.1 200 OK') {
                //echo "h element=".$h[0]."--";
                $this->img = $url;
                return true;
            }
        } catch (\Exception $e) {
        }

        return false;
    }

    /**
     * @param $file
     */
    public function getImage($file)
    {
        //echo "file is ".$file."--";
        $imgUrl = $this->storage->getObjectUrl(env('BUCKET'), $file);
        //echo "imgUrl is ".$imgUrl." --";
        $this->validateImg($imgUrl);
        //echo "right after validateImg --";

        return $this->display($file);
    }

    /**
     * @param $file
     * @return string
     */
    public function getImageUrl($file)
    {
        $imgUrl = $this->storage->getObjectUrl(env('BUCKET'), $file);

        if ($this->validateImg($imgUrl)) {
            return response()->json(['url' => $imgUrl], 200);
        }
        return response()->json(['error' => 'No Such File'], 404);
    }

    /**
     * @param $file
     * @param $height
     * @param $width
     */
    public function getImageResized($file, $height, $width)
    {
        $imgUrl = $this->storage->getObjectUrl(env('BUCKET'), $file);

        $this->validateImg($imgUrl);
        return $this->display($file, $height, $width);
    }
}
