<?php
 declare(strict_types=1);
 use Nicode\FormStudio\Domain\{Uuid, FieldAddress, RepeatedInstances};
 test('repeated membership preserves ordered rows and rejects forged or orphan scopes', function (): void {
     [$outer,$inner,$field,$flat,$one,$two,$child] = array_map(static fn()=>Uuid::create(),range(1,7));
     $elements=[['uuid'=>$outer,'type'=>'repeatable-group','repeat'=>['min'=>1,'max'=>3]],['uuid'=>$inner,'type'=>'repeatable-group','parent_uuid'=>$outer,'repeat'=>['min'=>1,'max'=>2]],['uuid'=>$field,'type'=>'field','parent_uuid'=>$inner],['uuid'=>$flat,'type'=>'field']];
     $scope=static fn($row)=>(new FieldAddress($inner,[['group'=>$outer,'instance'=>$row]]))->key();
     $declarations=[$scope($one)=>[$child],$outer=>[$two,$one]];
     $set=new RepeatedInstances($elements,$declarations);
     same([$two,$one],$set->declarations()[$outer]);
     same([$scope($two)=>['min_instances']],$set->minimumErrors());
     $address=new FieldAddress($field,[['group'=>$outer,'instance'=>$one],['group'=>$inner,'instance'=>$child]]);
     same(true,$set->contains($address)); same(true,$set->contains(new FieldAddress($flat)));
     same(false,$set->contains(new FieldAddress($field)));
     same(false,$set->contains(new FieldAddress($flat,$address->instances)));
     same(false,$set->contains(new FieldAddress($field,[['group'=>$outer,'instance'=>$two],['group'=>$inner,'instance'=>$child]])));
     same(false,$set->contains(new FieldAddress(Uuid::create())));
     $reordered=new RepeatedInstances($elements,[$outer=>[$one,$two],$scope($one)=>[$child]]);
     same(true,$reordered->contains($address));
     foreach ([[$outer=>[$one,$one]],[$outer=>[$one],$scope($two)=>[$child]],[$outer=>[$one,$two,$child,Uuid::create()]],[$flat=>[$one]],[$inner=>[$child]],[$outer=>['0']],[$outer=>[1=>$one]]] as $invalid) { raises(InvalidArgumentException::class,fn()=>new RepeatedInstances($elements,$invalid)); }
     $empty=new RepeatedInstances($elements,[]); same([$outer=>['min_instances']],$empty->minimumErrors());
     $cycle=$elements; $cycle[0]['parent_uuid']=$inner; raises(InvalidArgumentException::class,fn()=>new RepeatedInstances($cycle,[]));
     $invalid=$elements; $invalid[0]['repeat']['min']='1'; raises(InvalidArgumentException::class,fn()=>new RepeatedInstances($invalid,[]));
     raises(InvalidArgumentException::class,fn()=>new RepeatedInstances($elements,$declarations,1));
 });

 test('repeated expansion binds missing controls in row-major layout order without flattening multivalues', function (): void {
     [$group,$nested,$first,$second,$deep,$outside,$r1,$r2,$n1]=array_map(static fn()=>Uuid::create(),range(1,9));
     $elements=[['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]],['uuid'=>$first,'type'=>'field','parent_uuid'=>$group],['uuid'=>$nested,'type'=>'repeatable-group','parent_uuid'=>$group,'repeat'=>['min'=>0,'max'=>1]],['uuid'=>$deep,'type'=>'field','parent_uuid'=>$nested],['uuid'=>$second,'type'=>'field','parent_uuid'=>$group],['uuid'=>$outside,'type'=>'field']];
     $p1=[['group'=>$group,'instance'=>$r1]]; $p2=[['group'=>$group,'instance'=>$r2]];
     $nestedScope=(new FieldAddress($nested,$p1))->key();
     $set=new RepeatedInstances($elements,[$group=>[$r2,$r1],$nestedScope=>[$n1]]);
     $key=static fn($f,$p)=>(new FieldAddress($f,$p))->key();
     $expected=[$key($first,$p2),$key($second,$p2),$key($first,$p1),$key($deep,[...$p1,['group'=>$nested,'instance'=>$n1]]),$key($second,$p1),$outside];
     same($expected,array_map(static fn($address)=>$address->key(),$set->addresses()));
     $raw=[$outside=>false,$expected[2]=>['a','b'],$expected[0]=>0];
     $bound=$set->bind($raw); same($expected,array_keys($bound));
     same([0,null,['a','b'],null,null,false],array_values($bound));
     same(3,count($raw)); // Caller input is unchanged.
     raises(InvalidArgumentException::class,fn()=>$set->bind([$first=>'unscoped']));
     raises(InvalidArgumentException::class,fn()=>$set->bind([Uuid::create()=>'foreign']));
     raises(InvalidArgumentException::class,fn()=>$set->addresses(3));
     raises(InvalidArgumentException::class,fn()=>$set->bind($raw,3));
     $empty=new RepeatedInstances($elements,[]); same([$outside=>null],$empty->bind([]));
 });

 test('repeated input cannot bind fields below non-container leaves', function (): void {
     $parent=Uuid::create(); $field=Uuid::create();
     foreach (['field','text','unknown'] as $type) {
         raises(InvalidArgumentException::class,fn()=>new RepeatedInstances([['uuid'=>$parent,'type'=>$type],['uuid'=>$field,'type'=>'field','parent_uuid'=>$parent]],[]));
     }
 });

 test('repeated references resolve within their row or ancestors without selecting an arbitrary instance', function (): void {
     [$outer,$inner,$sibling,$rootField,$outerField,$left,$right,$other,$r1,$r2,$nestedRow,$otherRow]=array_map(static fn()=>Uuid::create(),range(1,12));
     $elements=[['uuid'=>$outer,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]],['uuid'=>$inner,'type'=>'repeatable-group','parent_uuid'=>$outer,'repeat'=>['min'=>0,'max'=>1]],['uuid'=>$sibling,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>1]],['uuid'=>$rootField,'type'=>'field'],['uuid'=>$outerField,'type'=>'field','parent_uuid'=>$outer],['uuid'=>$left,'type'=>'field','parent_uuid'=>$inner],['uuid'=>$right,'type'=>'field','parent_uuid'=>$inner],['uuid'=>$other,'type'=>'field','parent_uuid'=>$sibling]];
     $parents1=[['group'=>$outer,'instance'=>$r1]]; $parents2=[['group'=>$outer,'instance'=>$r2]];
     $deep1=[...$parents1,['group'=>$inner,'instance'=>$nestedRow]]; $deep2=[...$parents2,['group'=>$inner,'instance'=>$nestedRow]];
     $set=new RepeatedInstances($elements,[$outer=>[$r2,$r1],(new FieldAddress($inner,$parents1))->key()=>[$nestedRow],(new FieldAddress($inner,$parents2))->key()=>[$nestedRow],$sibling=>[$otherRow]]);
     foreach ([$deep1,$deep2] as $path) {
         $origin=new FieldAddress($left,$path);
         same((new FieldAddress($right,$path))->key(),$set->resolve($origin,$right)->key());
         same((new FieldAddress($outerField,array_slice($path,0,1)))->key(),$set->resolve($origin,$outerField)->key());
         same($rootField,$set->resolve($origin,$rootField)->key());
         raises(InvalidArgumentException::class,fn()=>$set->resolve($origin,$other));
         raises(InvalidArgumentException::class,fn()=>$set->resolve($origin,Uuid::create()));
     }
     raises(InvalidArgumentException::class,fn()=>$set->resolve(new FieldAddress($rootField),$left));
     raises(InvalidArgumentException::class,fn()=>$set->resolve(new FieldAddress($outerField,$parents1),$right));
     raises(InvalidArgumentException::class,fn()=>$set->resolve(new FieldAddress($left),$rootField));
 });
