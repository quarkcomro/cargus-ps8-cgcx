{*
 * views/templates/hook/display_carrier_extra_content.tpl
 * UI for selecting the Cargus Ship & Go PUDO point.
 *}

<div id="cargus-pudo-container" class="cargus-extra-content" style="display: none; margin-top: 10px; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px;">
    <h4 class="cargus-title">{l s='Choose your Ship & Go location' mod='cargus'}</h4>
    
    <div class="cargus-pudo-selectors">
        <input type="hidden" id="cargus_selected_pudo_id" name="cargus_selected_pudo_id" value="" />
        
        <div class="form-group">
            <label for="cargus_pudo_city">{l s='Search City' mod='cargus'}</label>
            <input type="text" id="cargus_pudo_city" class="form-control" placeholder="{l s='Start typing your city...' mod='cargus'}" autocomplete="off" />
            <div id="cargus_city_results" class="cargus-autocomplete-dropdown" style="display:none;"></div>
        </div>

        <div class="form-group" id="cargus_pudo_list_container" style="display:none;">
            <label for="cargus_pudo_select">{l s='Select Location' mod='cargus'}</label>
            <select id="cargus_pudo_select" class="form-control">
                <option value="">{l s='-- Please select a location --' mod='cargus'}</option>
            </select>
        </div>

        <div class="cargus-map-action" style="margin-top: 15px;">
            <button type="button" id="cargus_open_map_btn" class="btn btn-primary btn-sm">
                {l s='Choose on Map' mod='cargus'}
            </button>
        </div>
    </div>
    
    <div id="cargus-map-container" style="display:none; height: 400px; width: 100%; margin-top:15px; border-radius: 5px;"></div>
    
    <div id="cargus_pudo_selection_success" class="alert alert-success" style="display:none; margin-top: 10px;">
        {l s='Location saved successfully!' mod='cargus'}
    </div>
</div>

<script>
    // Pass necessary variables to JS
    var cargusAjaxUrl = "{$link->getModuleLink('cargus', 'ajax', [], true)|escape:'javascript':'UTF-8'}";
    var cargusCarrierId = "{$cargus_ship_go_id|escape:'javascript':'UTF-8'}";
    var cargusCsrfToken = prestashop ? prestashop.token : '';
</script>