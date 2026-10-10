{{-- P7A74iGD (b): the deploy scope of a Control D onboarding plan. 'none' (Control D steps only)
     is offered only on the onboarding form; the deploy-only form requires all or selected. --}}
<fieldset class="small border rounded p-2" data-testid="controld-deploy-scope">
    <legend class="small fw-semibold mb-1 float-none w-auto px-1">Deploy through Tactical</legend>
    @unless($cdRequired)
        <div class="form-check">
            <input class="form-check-input" type="radio" name="deploy_scope" value="none" id="cd-deploy-none-{{ $cdRequired ? 'd' : 'o' }}" checked>
            <label class="form-check-label" for="cd-deploy-none-{{ $cdRequired ? 'd' : 'o' }}">No deploy in this plan</label>
        </div>
    @endunless
    <div class="form-check">
        <input class="form-check-input" type="radio" name="deploy_scope" value="all" id="cd-deploy-all-{{ $cdRequired ? 'd' : 'o' }}" @if($cdRequired) checked required @endif>
        <label class="form-check-label" for="cd-deploy-all-{{ $cdRequired ? 'd' : 'o' }}">All devices Tactical lists under this client when approved</label>
    </div>
    <div class="form-check">
        <input class="form-check-input" type="radio" name="deploy_scope" value="selected" id="cd-deploy-selected-{{ $cdRequired ? 'd' : 'o' }}">
        <label class="form-check-label" for="cd-deploy-selected-{{ $cdRequired ? 'd' : 'o' }}">Only these devices:</label>
    </div>
    @if($cdDeployAssets->isEmpty())
        <div class="text-muted ms-4">No asset of this client is linked to a Tactical agent.</div>
    @else
        <select name="deploy_assets[]" class="form-select form-select-sm ms-4 w-auto" multiple size="{{ min(6, $cdDeployAssets->count()) }}">
            @foreach($cdDeployAssets as $cdAsset)
                <option value="{{ $cdAsset->id }}">{{ $cdAsset->name }} (#{{ $cdAsset->id }})</option>
            @endforeach
        </select>
    @endif
</fieldset>
