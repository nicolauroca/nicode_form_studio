<?php
declare(strict_types=1);
$responseAdmin = new Nicode\FormStudio\Application\SubmissionAdministration($connection, static fn (int $actor, ?int $form, string $permission): bool => $actor === 1);
$target = $submissions->persist($id, $indexedVersion, $spec, [$uuid => 'State fixture'], hash('sha256', random_bytes(32)));
try { $responseAdmin->changeState(2, $id, $target->id, 'new', 'reviewed'); throw new RuntimeException('Unauthorized response state edit.'); } catch (DomainException) {}
try { $responseAdmin->addNote(1, $multiForm, $target->id, 'wrong form'); throw new RuntimeException('Cross-form note added.'); } catch (OutOfBoundsException) {}
$canonicalBefore = $submissions->get($id, $target->id)['canonical_payload'];
$responseAdmin->changeState(1, $id, $target->id, 'new', 'reviewed');
try { $responseAdmin->changeState(1, $id, $target->id, 'new', 'spam'); throw new RuntimeException('Stale response state overwritten.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
$noteId = $responseAdmin->addNote(1, $id, $target->id, 'Literal <script>alert(1)</script> note');
$noteRow = $connection->row('SELECT body, created_by FROM ' . $connection->table('submission_notes') . ' WHERE id = :id', [':id' => $noteId]);
if ($noteRow['body'] !== 'Literal <script>alert(1)</script> note' || (int) $noteRow['created_by'] !== 1 || $submissions->get($id, $target->id)['canonical_payload'] !== $canonicalBefore) { throw new RuntimeException('Administrative edit changed canonical answers or note ownership.'); }
$noteAudit = $connection->row('SELECT safe_metadata FROM ' . $connection->table('audit_log') . " WHERE submission_uuid = :uuid AND event_type = 'submission.note' ORDER BY id DESC LIMIT 1", [':uuid' => $target->uuid]);
if (str_contains($noteAudit['safe_metadata'], 'script') || json_decode($noteAudit['safe_metadata'], true)['note_id'] !== $noteId) { throw new RuntimeException('Note audit copied private body.'); }
try { $responseAdmin->addNote(1, $id, $target->id, str_repeat('a', 16001)); throw new RuntimeException('Oversize note accepted.'); } catch (InvalidArgumentException) {}
$maintenance->apply($id, $target->id, 1, 'anonymize');
try { $responseAdmin->addNote(1, $id, $target->id, 'after erasure'); throw new RuntimeException('Anonymized response received a note.'); } catch (DomainException) {}
echo "Response administration: per-form permissions, optimistic state, literal notes, minimal audit and anonymization boundary verified.\n";

(static function()use($connection,$submissions,$responseAdmin,$id,$indexedVersion,$spec,$uuid,$multiForm):void {
    $states=Nicode\FormStudio\Application\SubmissionAdministration::STATES;
    foreach($states as $from) {
        foreach($states as $to) {
            $response=$submissions->persist($id,$indexedVersion,$spec,[$uuid=>'State transition private value'],hash('sha256',random_bytes(32)));
            if($from!=='new') { $responseAdmin->changeState(1,$id,$response->id,'new',$from); }
            $connection->execute('UPDATE '.$connection->table('submissions')." SET action_status='partial_failure' WHERE id=:id",[':id'=>$response->id]);
            $before=$submissions->get($id,$response->id);
            $audit=static fn()=>$connection->rows('SELECT actor_id,form_id,safe_metadata FROM '.$connection->table('audit_log')." WHERE submission_uuid=:uuid AND event_type='submission.state' ORDER BY id",[':uuid'=>$response->uuid]);
            $prior=$audit(); $responseAdmin->changeState(1,$id,$response->id,$from,$to); $after=$submissions->get($id,$response->id); $events=$audit();
            $expected=$before; $expected['state']=$to;
            if($after!==$expected || count($events)!==count($prior)+($from===$to?0:1)) { throw new RuntimeException('State transition changed canonical/history metadata or audited a no-op.'); }
            if($from!==$to) {
                $event=end($events); $metadata=json_decode($event['safe_metadata'],true,flags:JSON_THROW_ON_ERROR);
                if($metadata!==['from'=>$from,'to'=>$to] || (int)$event['actor_id']!==1 || (int)$event['form_id']!==$id) { throw new RuntimeException('State audit lost typed origin/destination or trusted ownership.'); }
                $viewer=new Nicode\FormStudio\Application\AuditLog($connection,static fn():bool=>true);
                $visible=$viewer->page(1,['submission_uuid'=>$response->uuid,'event_type'=>'submission.state']);
                if($visible['rows'][0]['details']!==$metadata) { throw new RuntimeException('Audit viewer omitted state transition details.'); }
                try { $responseAdmin->changeState(1,$id,$response->id,$from,$from); throw new LogicException('Stale transition overwrote current state.'); } catch(Nicode\FormStudio\Domain\ConcurrentEdit) {}
            }
            foreach(['core.manage','formstudio.submissions.view','formstudio.submissions.manage'] as $denied) {
                $restricted=new Nicode\FormStudio\Application\SubmissionAdministration($connection,static fn(int $actor,?int $scope,string $permission):bool=>$permission!==$denied);
                try { $restricted->changeState(1,$id,$response->id,$to,'new'); throw new LogicException('Independent state permission bypassed.'); } catch(DomainException) {}
            }
            try { $responseAdmin->changeState(1,$multiForm,$response->id,$to,'new'); throw new LogicException('Cross-form transition accepted.'); } catch(OutOfBoundsException) {}
            foreach([['invalid',$to],[$to,'invalid']] as [$expectedState,$next]) {
                try { $responseAdmin->changeState(1,$id,$response->id,$expectedState,$next); throw new LogicException('Unknown state accepted.'); } catch(InvalidArgumentException) {}
            }
            if($submissions->get($id,$response->id)!==$after || $audit()!==$events) { throw new RuntimeException('Rejected state transition changed data or audit.'); }
        }
    }
    echo "Response state matrix: all ".count($states)**2 ." transitions, no-op audit, action-status/payload/history isolation, three independent permissions, stale/cross-form/unknown rejection passed.\n";
})();
