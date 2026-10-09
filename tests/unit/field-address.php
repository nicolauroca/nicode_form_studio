<?php
 declare(strict_types=1);
 use Nicode\FormStudio\Domain\FieldAddress;
 use Nicode\FormStudio\Domain\Uuid;
 test('repeated field addresses preserve ancestry and identity independently of row positions', function (): void {
     $field=Uuid::create(); $group=Uuid::create(); $row=Uuid::create(); $nested=Uuid::create();
     $path=new FieldAddress($field,[['group'=>$group,'instance'=>$row],['group'=>$nested,'instance'=>Uuid::create()]]);
     same($path->instances,FieldAddress::fromKey($path->key())->instances);
     same($field,(new FieldAddress($field))->key());
     same($field,FieldAddress::fromKey($field)->field);
     foreach (['', $field.'/', '/'.$field, $group.'/'.$row, $field."\n", strtoupper('abcdefab-cdef-4abc-8abc-abcdefabcdef'), $group.'/'.$row.'/'.$group.'/'.$row.'/'.$field] as $invalid) { raises(InvalidArgumentException::class,fn()=>FieldAddress::fromKey($invalid)); }
     raises(InvalidArgumentException::class,fn()=>new FieldAddress($field,[['group'=>$field,'instance'=>$row]]));
     raises(InvalidArgumentException::class,fn()=>new FieldAddress($field,[['group'=>$group,'instance'=>$row,'position'=>0]]));
     $instances=[]; for($i=0;$i<64;$i++) $instances[]=['group'=>Uuid::create(),'instance'=>Uuid::create()];
     $deep=new FieldAddress($field,$instances); same(4772,strlen($deep->key())); same($instances,FieldAddress::fromKey($deep->key())->instances);
     $instances[]=['group'=>Uuid::create(),'instance'=>Uuid::create()]; raises(InvalidArgumentException::class,fn()=>new FieldAddress($field,$instances));
 });
