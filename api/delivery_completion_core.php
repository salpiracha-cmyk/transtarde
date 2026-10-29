<?php
declare(strict_types=1);
/** Delivery completion only. Never settles a supplier/customer money balance. */
function dc_complete(float $contractedMT,float $loadedMT,bool $known=true,bool $documentsClosed=false):bool {
    return $documentsClosed||($known&&$contractedMT>0&&$loadedMT>0&&$loadedMT+.500000001>=$contractedMT);
}
function dc_remaining(float $contractedMT,float $loadedMT,bool $known=true,bool $documentsClosed=false):?float {
    return dc_complete($contractedMT,$loadedMT,$known,$documentsClosed)?0.:($known?round(max(0,$contractedMT-$loadedMT),6):null);
}
