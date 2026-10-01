<?php
declare(strict_types=1);

// Search/display aliases never replace the persisted ID or external invoice number.
function tt_accounts_reference_matches(string $query, mixed $reference): bool {
    $reference=(string)$reference;
    if(!preg_match('/^(?:[A-Z]+-)?(\d{4})-(\d+)$/i',$reference,$ref))return false;
    if(preg_match('/^(?:(\d{4})-)?(\d+)$/',$query,$input))
        return ($input[1]===''||$input[1]===$ref[1])&&ltrim($input[2],'0')===ltrim($ref[2],'0');
    return false;
}
