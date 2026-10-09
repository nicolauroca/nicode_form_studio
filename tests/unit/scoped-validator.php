<?php
declare(strict_types=1);
use Nicode\FormStudio\Domain\{Uuid, FieldAddress, RepeatedInstances};
use Nicode\FormStudio\Validation\{ScopedValidator, RelationalValidator};

test('scoped relational validation isolates sibling values and addresses errors to the correct row', function (): void {
    [$group,$left,$right,$shared,$one,$two] = array_map(static fn()=>Uuid::create(),range(1,6));
    $instances = new RepeatedInstances([['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]],['uuid'=>$left,'type'=>'field','parent_uuid'=>$group],['uuid'=>$right,'type'=>'field','parent_uuid'=>$group],['uuid'=>$shared,'type'=>'field']],[$group=>[$one,$two]]);
    $address=static fn($field,$row)=>new FieldAddress($field,[['group'=>$group,'instance'=>$row]]);
    $l1=$address($left,$one); $r1=$address($right,$one); $l2=$address($left,$two); $r2=$address($right,$two);
    $values=[$l1->key()=>'A',$r1->key()=>'A',$l2->key()=>'A',$r2->key()=>'B',$shared=>'A'];
    $types=[$left=>'text',$right=>'text',$shared=>'text']; $provider=new RelationalValidator('confirmation');
    same([],ScopedValidator::validate($provider,['fields'=>[$left,$right]],$l1,$instances,$values,$types));
    $errors=ScopedValidator::validate($provider,['fields'=>[$left,$right]],$l2,$instances,$values,$types);
    same('cross.confirmation',$errors[0]->code); same([$l2->key(),$r2->key()],$errors[0]->fields);
    same([],ScopedValidator::validate($provider,['fields'=>[$left,$shared]],$l2,$instances,$values,$types));
    unset($values[$r2->key()]);
    same([],ScopedValidator::validate($provider,['fields'=>[$left,$right]],$l2,$instances,$values,$types));
    raises(InvalidArgumentException::class,fn()=>ScopedValidator::validate($provider,['fields'=>[$left,$right]],new FieldAddress($shared),$instances,$values,$types));
});
