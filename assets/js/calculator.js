const inputs = document.querySelectorAll(".kg-input");

const totalValue = document.getElementById("totalValue");
const summary = document.getElementById("summary");
const breakdown = document.getElementById("breakdown");

function calculate(){

    let total=0;
    let totalKg=0;
    let used=0;

    breakdown.innerHTML="";

    inputs.forEach(input=>{

        const kg=parseFloat(input.value)||0;
        const price=parseFloat(input.dataset.price);
        const name=input.dataset.name;

        if(kg>0){

            used++;

            const subtotal=kg*price;

            total+=subtotal;
            totalKg+=kg;

            breakdown.innerHTML+=`
            <div class="d-flex justify-content-between border-bottom py-2">
                <span>${name}</span>
                <span>RM ${subtotal.toFixed(2)}</span>
            </div>`;
        }

    });

    totalValue.innerHTML="RM "+total.toFixed(2);

    summary.innerHTML=
        `${used} of ${inputs.length} categories · ${totalKg.toFixed(1)} kg total`;
}

inputs.forEach(input=>{
    input.addEventListener("input",calculate);
});

calculate();