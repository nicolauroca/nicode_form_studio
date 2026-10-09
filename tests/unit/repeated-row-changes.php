<?php
declare(strict_types=1);
use Nicode\FormStudio\Domain\{Uuid, RepeatedInstances, FieldAddress};

test('row changes preserve siblings seed nested minima and remove only the selected subtree', function (): void {
    [$outer,$inner,$field,$one,$two,$shared] = array_map(static fn()=>Uuid::create(),range(1,6));
    $elements = [
        ['uuid'=>$outer,'type'=>'repeatable-group','repeat'=>['min'=>1,'max'=>3]],
        ['uuid'=>$inner,'type'=>'repeatable-group','parent_uuid'=>$outer,'repeat'=>['min'=>1,'max'=>2]],
        ['uuid'=>$field,'type'=>'field','parent_uuid'=>$inner],
    ];
    $scope = static fn($row)=>new FieldAddress($inner,[['group'=>$outer,'instance'=>$row]]);
    $rows = [$outer=>[$one,$two],$scope($one)->key()=>[$shared],$scope($two)->key()=>[$shared]];
    $original = new RepeatedInstances($elements,$rows);
    $added = $original->withAddedRow(new FieldAddress($outer));
    same($rows,$original->declarations());
    $newRows = $added->declarations()[$outer]; same([$one,$two],array_slice($newRows,0,2)); same(3,count($newRows));
    same(1,count($added->declarations()[$scope($newRows[2])->key()]));
    same([],$added->minimumErrors());
    raises(InvalidArgumentException::class,fn()=>$added->withAddedRow(new FieldAddress($outer)));
    $removed = $added->withRemovedRow(new FieldAddress($outer),$one);
    same([$two,$newRows[2]],$removed->declarations()[$outer]);
    same(false,isset($removed->declarations()[$scope($one)->key()]));
    same([$shared],$removed->declarations()[$scope($two)->key()]);
    $nested = $removed->withAddedRow($scope($two));
    same(2,count($nested->declarations()[$scope($two)->key()]));
    $back = $nested->withRemovedRow($scope($two),$nested->declarations()[$scope($two)->key()][1]);
    same($removed->declarations(),$back->declarations());
    raises(InvalidArgumentException::class,fn()=>$back->withRemovedRow($scope($two),$shared));
    raises(InvalidArgumentException::class,fn()=>$back->withAddedRow($scope($one)));
    raises(InvalidArgumentException::class,fn()=>$back->withRemovedRow(new FieldAddress($outer),Uuid::create()));
    $old = new FieldAddress($field,[['group'=>$outer,'instance'=>$one],['group'=>$inner,'instance'=>$shared]]);
    raises(InvalidArgumentException::class,fn()=>$removed->bind([$old->key()=>'stale']));
    raises(InvalidArgumentException::class,fn()=>$original->withAddedRow(new FieldAddress($outer),6));
    same($rows,$original->declarations());
});
